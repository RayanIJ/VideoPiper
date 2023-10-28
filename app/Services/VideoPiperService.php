<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoPiperService
{
    private Client $client;
    private int $totalBytes = 0;
    private int $downloaded = 0;
    private int $progress = 0;
    private string $error;
    private string $link;
    private string $filename;
    private string $title;


    /**
     * VideoPiperService constructor.
     * @description initialize the client and error and link variables
     */
    public function __construct()
    {
        $this->client = new Client([
            'handler' => HandlerStack::create(),
        ]);
        $this->error = 'null';
        $this->link = 'null';
        $this->title = 'null';
        $this->filename = md5(uniqid(rand(), true));
    }

    /**
     * @param string $url
     * @return StreamedResponse
     * @description download the video from the given url and return a streamed response
     */
    public function download(string $url): StreamedResponse
    {
        return response()->stream(function () use ($url) {

            $process = $this->executeCommand($url); // 0 => resource, 1 => pipes

            if (is_resource($process[0])) { // if yt-dlp process is running
                $result = $this->getYtDlpJson($process[0], $process[1]); // $process[0] => resource, $process[1] => pipes
                $yt_dlp_json = $result['yt_dlp_json'];
                $errors = $result['errors'];

                if ($yt_dlp_json !== null) {
                    $this->recordFileSize($yt_dlp_json['url']);
                    $this->downloadFile($yt_dlp_json['url'], $yt_dlp_json['filename']);
                } else
                    $this->checkForErrors($errors);
            }
        }, 200, [
            'Connection' => 'keep-alive',
        ]);
    }


    /**
     * @param string $url
     * @return array
     * @description execute yt-dlp command and return the process resource and pipes
     */
    private function executeCommand(string $url): array
    {
        $cmd = __DIR__ . '/../Downloader/yt-dlp --no-check-certificate --force-ipv4 "' . $url . '"';

        $yt_dlp = proc_open($cmd, [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ], $pipes);

        return [$yt_dlp, $pipes];
    }

    /**
     * @param $yt_dlp
     * @param array $pipes
     * @return array
     * @description get yt-dlp output and errors from the process resource and pipes
     */
    private function getYtDlpJson($yt_dlp, array $pipes): array
    {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($yt_dlp);

        preg_match('/\{.*\}/', $output, $matches);
        if (isset($matches[0])) {
            $yt_dlp_json = json_decode($matches[0], true) ?? null;
            $this->plugFilenameExtension($yt_dlp_json['filename']);
            $this->title = $yt_dlp_json['filename'];

            return ['yt_dlp_json' => $yt_dlp_json, 'errors' => $errors];
        }

        return ['yt_dlp_json' => null, 'errors' => $errors];
    }

    /**
     * @param $filename
     * @return void
     * @description plug the file extension to the filename
     */
    function plugFilenameExtension($filename): void
    {
        // get the file extension
        preg_match('/\.[a-zA-Z0-9]{1,5}$/', $filename, $matches);
        $extension = $matches[0] ?? '.mp4';
        $this->filename .= $extension;
    }

    /**
     * @param $errors
     * @return void
     * @description check for errors and return them to the client if any
     */
    private function checkForErrors($errors): void
    {
        if ($errors && str_contains($errors, '--cookies')) {
            $this->error = 'Rate limit exceeded';
            $this->pipe();
            die();
        } else if ($errors) {
            $this->error = $errors;
            $this->pipe();
            die();
        }
    }

    /**
     * @param $url
     * @return void
     * @description record the file size of the given url
     * @throws GuzzleException
     */
    private function recordFileSize($url): void
    {
        $this->client->get($url, [
            'on_headers' => function ($response) {
                $this->totalBytes = $response->getHeader('Content-Length')[0];
            },
            'verify' => false,
            'stream' => true
        ]);

        if ($this->totalBytes > 500000000) { // > 500MB
            $this->error = 'File size is too large';
            $this->pipe();
            die();
        }

        $this->pipe();
    }

    /**
     * @param $url
     * @return void
     * @throws GuzzleException
     * @description download the file from the given url and save it to the original filename
     */
    private function downloadFile($url): void
    {
        $start_time = microtime(true);
        $response = $this->client->get($url, [
            'progress' => function (
                $downloadTotal, $downloadedBytes,
                $uploadTotal, $uploadedBytes
            ) use (&$start_time) {
                $this->totalBytes = $downloadTotal;
                $this->downloaded = $downloadedBytes;
                $this->progress = $downloadTotal > 0 ? round(($downloadedBytes / $downloadTotal) * 100) : 0;

                if (microtime(true) - $start_time >= 3) {
                    $start_time = microtime(true);
                    $this->pipe();
                }
            },
            'sink' => Storage::disk('public')->path($this->filename),
            'verify' => false
        ]);

        $this->link = Storage::disk('public')->url($this->filename);
        $this->pipe();
    }


    /**
     * @return void
     * @description send the download progress to the client
     */
    function pipe(): void
    {
        $data = [
            'title' => $this->title,
            'link' => $this->link,
            'total' => $this->totalBytes,
            'downloaded' => $this->downloaded,
            'progress' => $this->progress,
            'error' => $this->error,
        ];
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);

        echo $data . "\n";
        if (ob_get_level() > 0)
            ob_flush();
        flush();
    }

}
