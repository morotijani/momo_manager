<?php

declare(strict_types=1);

namespace App\Core;

final class HttpResponseException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly array $payload
    ) {
        parent::__construct($payload['message'] ?? 'HTTP Response', $status);
    }
}

final class Response
{
    public static bool $captureMode = false;

    /**
     * @return never
     */
    public static function json(array $data, int $status = 200): never
    {
        if (self::$captureMode) {
            throw new HttpResponseException($status, $data);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @return never
     */
    public static function success(array $data = [], string $message = 'OK', int $status = 200): never
    {
        self::json(array_merge([
            'ok' => true,
            'message' => $message,
        ], $data), $status);
    }

    /**
     * @return never
     */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = [
            'ok' => false,
            'message' => $message,
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $status);
    }

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     * @return never
     */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        if (self::$captureMode) {
            throw new HttpResponseException(200, [
                'ok' => true,
                'csv_filename' => $filename,
                'csv_headers' => $headers,
                'csv_rows' => $rows,
            ]);
        }

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) . '"');
            header('Cache-Control: no-store, no-cache');
        }

        $out = fopen('php://output', 'wb');
        if ($out !== false) {
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel clarity
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }
        exit;
    }
}
