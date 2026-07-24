<?php

namespace Paymenter\Extensions\Gateways\Yoco;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\InvoiceTransaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

#[ExtensionMeta(
    name: 'Yoco Gateway',
    description: 'Accept payments through Yoco Checkout.',
    version: '0.1.0',
    author: 'vPay',
    url: 'https://www.yoco.com/za/gateway/',
    icon: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNTEyIiBoZWlnaHQ9IjUxMiIgdmlld0JveD0iMCAwIDUxMiA1MTIiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZyI+PHJlY3Qgd2lkdGg9IjUxMiIgaGVpZ2h0PSI1MTIiIHJ4PSI5NiIgZmlsbD0iIzAwNjM1QiIvPjxjaXJjbGUgY3g9IjI1NiIgY3k9IjI1NiIgcj0iMTQwIiBmaWxsPSIjRkZGRkZGIi8+PHBhdGggZD0iTTE3NiAyMTJIMjI4TDI1NiAyNjhMMjg0IDIxMkgzMzZMMjc4IDMyMEgyMzRMMTc2IDIxMloiIGZpbGw9IiMwMDYzNUIiLz48L3N2Zz4='
)]
class Yoco extends Gateway
{
    private const DEFAULT_API_BASE = 'https://payments.yoco.com/api';

    public function boot()
    {
        require __DIR__ . '/routes.php';
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'yoco_secret_key',
                'label' => 'Yoco Secret Key',
                'placeholder' => 'sk_test_... or sk_live_...',
                'type' => 'text',
                'description' => 'Yoco Checkout API secret key. Keep this private and never expose it in browser code.',
                'required' => true,
            ],
            [
                'name' => 'yoco_webhook_secret',
                'label' => 'Yoco Webhook Secret',
                'placeholder' => 'whsec_...',
                'type' => 'text',
                'description' => 'Webhook signing secret returned when creating the Yoco webhook subscription.',
                'required' => true,
            ],
            [
                'name' => 'yoco_api_base',
                'label' => 'Yoco API Base URL',
                'placeholder' => self::DEFAULT_API_BASE,
                'type' => 'text',
                'description' => 'Leave as default unless Yoco support tells you otherwise.',
                'required' => false,
            ],
        ];
    }

    public function canUseGateway($total, $currency, $type, $items = [])
    {
        if (strtoupper((string) $currency) !== 'ZAR') {
            return false;
        }

        if ((float) $total <= 0) {
            return false;
        }

        return true;
    }

    public function pay($invoice, $total)
    {
        $amountInCents = (int) round(((float) $total) * 100);

        if ($amountInCents <= 0) {
            throw new Exception('Invalid Yoco payment amount.');
        }

        $returnUrl = route('extensions.gateways.yoco.return', ['invoice' => $invoice->id]);
        $cancelUrl = route('extensions.gateways.yoco.cancel', ['invoice' => $invoice->id]);

        $checkout = $this->request('post', '/checkouts', [
            'amount' => $amountInCents,
            'currency' => 'ZAR',
            'successUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'metadata' => [
                'invoice_id' => (string) $invoice->id,
                'invoice_number' => (string) ($invoice->number ?? $invoice->id),
                'gateway' => 'vpay-yoco',
            ],
        ], (string) Str::uuid());

        if (!isset($checkout->redirectUrl) || empty($checkout->redirectUrl)) {
            throw new Exception('Yoco checkout did not return a redirect URL.');
        }

        return $checkout->redirectUrl;
    }

    public function webhook(Request $request)
    {
        $rawBody = $request->getContent();

        if (!$this->isValidSignature($rawBody, $request, $this->config('yoco_webhook_secret'))) {
            logger()->warning('Yoco webhook rejected because signature validation failed.');

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            logger()->warning('Yoco webhook rejected because JSON payload could not be decoded.');

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $type = data_get($event, 'type');
        $payload = data_get($event, 'payload', []);
        $metadata = data_get($payload, 'metadata', []);

        $invoiceId = $metadata['invoice_id'] ?? $metadata['invoiceId'] ?? null;

        if (!$invoiceId) {
            logger()->info('Yoco webhook ignored because invoice metadata was missing.', [
                'event_id' => data_get($event, 'id'),
                'event_type' => $type,
            ]);

            return response()->json(['ok' => true, 'ignored' => 'missing_invoice_metadata']);
        }

        $amountInCents = data_get($payload, 'amount');
        $amount = is_numeric($amountInCents) ? ((float) $amountInCents / 100) : 0;

        $transactionId = data_get($payload, 'id')
            ?: data_get($payload, 'paymentId')
            ?: data_get($event, 'id')
            ?: 'yoco-' . $invoiceId . '-' . sha1($rawBody);

        if (InvoiceTransaction::where('transaction_id', $transactionId)->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        if ($amount <= 0 && in_array($type, ['payment.succeeded', 'payment.failed'], true)) {
            logger()->warning('Yoco webhook ignored because amount was missing or invalid.', [
                'event_id' => data_get($event, 'id'),
                'event_type' => $type,
                'invoice_id' => $invoiceId,
                'transaction_id' => $transactionId,
            ]);

            return response()->json(['ok' => true, 'ignored' => 'missing_or_invalid_amount']);
        }

        try {
            switch ($type) {
                case 'payment.succeeded':
                    ExtensionHelper::addPayment($invoiceId, 'Yoco', $amount, null, $transactionId);
                    break;

                case 'payment.failed':
                    ExtensionHelper::addFailedPayment($invoiceId, 'Yoco', $amount, null, $transactionId);
                    break;

                default:
                    logger()->info('Yoco webhook event type ignored.', [
                        'event_id' => data_get($event, 'id'),
                        'event_type' => $type,
                        'invoice_id' => $invoiceId,
                    ]);
                    break;
            }
        } catch (Throwable $e) {
            logger()->error('Yoco webhook processing failed.', [
                'event_id' => data_get($event, 'id'),
                'event_type' => $type,
                'invoice_id' => $invoiceId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        return response()->json(['ok' => true]);
    }

    public function returnFromYoco(Request $request, $invoice)
    {
        return redirect()->to($this->invoiceUrl($invoice));
    }

    public function cancelFromYoco(Request $request, $invoice)
    {
        return redirect()->to($this->invoiceUrl($invoice));
    }

    private function invoiceUrl($invoiceId): string
    {
        $candidateRoutes = [
            'account.invoices.show',
            'account.invoice.show',
            'invoices.show',
            'invoice.show',
        ];

        foreach ($candidateRoutes as $routeName) {
            if (Route::has($routeName)) {
                return route($routeName, ['invoice' => $invoiceId]);
            }
        }

        return url('/account/invoices/' . $invoiceId);
    }

    private function request($method, $url, $data = [], $idempotencyKey = null)
    {
        $secretKey = $this->config('yoco_secret_key');

        if (empty($secretKey)) {
            throw new Exception('Yoco secret key is not configured.');
        }

        $request = Http::withToken($secretKey)
            ->acceptJson()
            ->asJson();

        if ($idempotencyKey) {
            $request = $request->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        return $request->{$method}($this->apiBase() . $url, $data)
            ->throw()
            ->object();
    }

    private function apiBase(): string
    {
        return rtrim($this->config('yoco_api_base') ?: self::DEFAULT_API_BASE, '/');
    }

    private function isValidSignature(string $payload, Request $request, ?string $secret): bool
    {
        if (empty($secret) || !str_starts_with($secret, 'whsec_')) {
            return false;
        }

        $webhookId = $request->header('webhook-id');
        $webhookTimestamp = $request->header('webhook-timestamp');
        $webhookSignature = $request->header('webhook-signature');

        if (empty($webhookId) || empty($webhookTimestamp) || empty($webhookSignature)) {
            return false;
        }

        if (!ctype_digit((string) $webhookTimestamp)) {
            return false;
        }

        $timestamp = (int) $webhookTimestamp;

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $decodedSecret = base64_decode(substr($secret, strlen('whsec_')), true);

        if ($decodedSecret === false || $decodedSecret === '') {
            return false;
        }

        $signedPayload = $webhookId . '.' . $webhookTimestamp . '.' . $payload;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $decodedSecret, true));

        foreach (preg_split('/\s+/', trim($webhookSignature)) as $signaturePart) {
            if (!str_starts_with($signaturePart, 'v1,')) {
                continue;
            }

            $actualSignature = substr($signaturePart, 3);

            if (hash_equals($expectedSignature, $actualSignature)) {
                return true;
            }
        }

        return false;
    }
}