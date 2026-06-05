<?php

namespace Fleetbase\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TermiiSmsService
{
    protected string $apiKey;

    protected string $from;

    protected string $baseUrl;

    protected string $channel;

    protected string $type;

    /**
     * Create a new TermiiSmsService instance.
     */
    public function __construct()
    {
        $this->apiKey  = config('services.termii.api_key', '');
        $this->from    = config('services.termii.from', '');
        $this->baseUrl = config('services.termii.base_url', 'https://v3.api.termii.com');
        $this->channel = config('services.termii.channel', 'dnd');
        $this->type    = config('services.termii.type', 'plain');

        Log::info('TermiiSmsService initialized', [
            'base_url' => $this->baseUrl,
            'from'     => $this->from,
            'channel'  => $this->channel,
            'type'     => $this->type,
        ]);
    }

    /**
     * Send an SMS message (static convenience method).
     *
     * @param string      $to   Recipient phone number in E.164 format
     * @param string      $text Message text
     * @param string|null $from Optional sender name or number, defaults to config
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public static function sendSms(string $to, string $text, ?string $from = null): array
    {
        $instance = new static();

        return $instance->send($to, $text, $from);
    }

    /**
     * Send an SMS message.
     *
     * @param string      $to   Recipient phone number in E.164 format
     * @param string      $text Message text
     * @param string|null $from Optional sender name or number, defaults to config
     * @return array Response containing status and message ID
     *
     * @throws \Exception If API request fails
     */
    public function send(string $to, string $text, ?string $from = null): array
    {
        $from = $from ?? $this->from;

        $this->validateParameters($to, $text, $from);

        try {
            Log::info('Sending SMS via Termii', [
                'to'   => $to,
                'from' => $from,
                'text' => substr($text, 0, 50) . (strlen($text) > 50 ? '...' : ''),
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/api/sms/send", [
                'api_key' => $this->apiKey,
                'to'      => $this->normalizePhoneNumber($to),
                'from'    => $from,
                'sms'     => $text,
                'type'    => $this->type,
                'channel' => $this->channel,
            ]);

            $statusCode = $response->status();
            $body       = $response->json();

            if ($response->successful() && strtolower($body['code'] ?? '') === 'ok') {
                Log::info('SMS sent successfully via Termii', [
                    'message_id' => $body['message_id'] ?? null,
                    'result'     => $body,
                ]);

                return [
                    'success'    => true,
                    'message_id' => $body['message_id'] ?? null,
                    'result'     => $body,
                ];
            }

            $errorMessage = $this->getErrorMessage($statusCode, $body);

            Log::error('Termii SMS sending failed', [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
                'response'    => $body,
            ]);

            throw new \Exception('Termii SMS failed: ' . $errorMessage);
        } catch (\Throwable $e) {
            Log::error('Termii SMS API request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception('Failed to send SMS: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate Termii SMS parameters.
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    protected function validateParameters(string $to, string $text, string $from): void
    {
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('Termii API key is not configured');
        }

        if (empty($from)) {
            throw new \InvalidArgumentException('Termii sender ID (from) is not configured');
        }

        if (empty($to)) {
            throw new \InvalidArgumentException('Recipient phone number (to) cannot be empty');
        }

        if (empty($text)) {
            throw new \InvalidArgumentException('Message text cannot be empty');
        }

        if (strlen($text) > 512) {
            throw new \InvalidArgumentException('Message text cannot exceed 512 characters');
        }
    }

    /**
     * Normalize a phone number for Termii.
     *
     * Termii expects international numbers without the leading plus sign.
     */
    protected function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/[^0-9]/', '', $phoneNumber);
    }

    /**
     * Get error message from Termii payload.
     */
    protected function getErrorMessage(int $statusCode, array $body): string
    {
        if (!empty($body['message'])) {
            return (string) $body['message'];
        }

        if (!empty($body['error'])) {
            return is_string($body['error']) ? $body['error'] : json_encode($body['error']);
        }

        return "Termii API request failed with status code: {$statusCode}";
    }

    /**
     * Check if the service is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->from);
    }

    /**
     * Get the configured sender ID.
     */
    public function getFrom(): string
    {
        return $this->from;
    }

    /**
     * Get the API base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
