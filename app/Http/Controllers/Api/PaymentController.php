<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use KHQR\BakongKHQR;
use KHQR\Helpers\KHQRData;
use KHQR\Models\IndividualInfo;

class PaymentController extends Controller
{
    private function getBakongStatusCode($response): ?int
    {
        if (!isset($response->status)) return null;
        if (is_array($response->status))  return $response->status['code'] ?? null;
        if (is_object($response->status)) return $response->status->code   ?? null;
        return null;
    }

    private function getBakongData($response, string $key = null)
    {
        if (!isset($response->data) || empty($response->data)) return null;
        if ($key === null) return $response->data;
        if (is_array($response->data))  return $response->data[$key]  ?? null;
        if (is_object($response->data)) return $response->data->$key  ?? null;
        return null;
    }

    public function initiate(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can initiate payment.'], 400);
        }

        // Reuse QR if still valid
        if ($order->qr_string && $order->md5 && $order->qr_expires_at && now()->lt($order->qr_expires_at)) {
            return response()->json([
                'message'    => 'Existing QR is still valid',
                'qr_string'  => $order->qr_string,
                'md5'        => $order->md5,
                'amount'     => $order->total_amount,
                'expires_at' => $order->qr_expires_at,
            ]);
        }

        $expiresAt   = now()->addMinutes(10);
        $expiresInMs = strval((int) ($expiresAt->timestamp * 1000));

        $individualInfo = new IndividualInfo(
            bakongAccountID:     env('BAKONG_ACCOUNT_USERNAME'),
            merchantName:        env('BAKONG_ACCOUNT_NAME', 'Clothes Store'),
            merchantCity:        env('BAKONG_LOCATION', 'Phnom Penh'),
            currency:            KHQRData::CURRENCY_USD,
            amount:              (float) $order->total_amount,
            expirationTimestamp: $expiresInMs,
        );

        $response   = BakongKHQR::generateIndividual($individualInfo);
        $statusCode = $this->getBakongStatusCode($response);

        if ($statusCode !== 0) {
            \Log::error('QR generation failed', ['response' => $response]);
            return response()->json(['message' => 'Failed to generate QR code.'], 500);
        }

        $qr  = $this->getBakongData($response, 'qr');
        $md5 = $this->getBakongData($response, 'md5');

        $order->update([
            'qr_string'     => $qr,
            'md5'           => $md5,
            'qr_expires_at' => $expiresAt,
        ]);

        return response()->json([
            'message'    => 'Payment initiated successfully',
            'qr_string'  => $qr,
            'md5'        => $md5,
            'amount'     => $order->total_amount,
            'expires_at' => $expiresAt,
        ]);
    }

    public function status(Request $request, Order $order)
{
    if ($order->user_id !== $request->user()->id) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    // Already paid — return immediately
    if ($order->status === 'paid') {
        return response()->json(['payment_status' => 'PAID']);
    }

    if (!$order->md5) {
        return response()->json(['payment_status' => 'PENDING']);
    }

    try {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('BAKONG_TOKEN'),
            'Content-Type'  => 'application/json',
        ])->post('https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5', [
            'md5' => $order->md5,
        ]);

        $body = $response->json();

        // ✅ Log everything so we can see what Bakong returns
        \Log::info('Bakong full response', [
            'order_id'    => $order->id,
            'md5'         => $order->md5,
            'http_status' => $response->status(),
            'body'        => $body,
        ]);

        // ✅ Check all possible success response structures
        $isPaid = false;

        // Structure 1: responseCode = 0 with data
        if (isset($body['responseCode']) && $body['responseCode'] === 0 && !empty($body['data'])) {
            $isPaid = true;
        }

        // Structure 2: status = 0 with data
        if (isset($body['status']) && $body['status'] === 0 && !empty($body['data'])) {
            $isPaid = true;
        }

        // Structure 3: data exists and not empty
        if (!$isPaid && isset($body['data']) && !empty($body['data'])) {
            $isPaid = true;
        }

        if ($isPaid) {
            $order->update(['status' => 'paid']);
            $order->load(['items.product', 'items.variant', 'user']);
            $this->sendTelegramPaymentConfirmed($order);
            return response()->json(['payment_status' => 'PAID']);
        }

        // Check expired
        if ($order->qr_expires_at && now()->gt($order->qr_expires_at)) {
            return response()->json(['payment_status' => 'EXPIRED']);
        }

        return response()->json(['payment_status' => 'PENDING']);

    } catch (\Exception $e) {
        \Log::error('Bakong status check failed', ['error' => $e->getMessage()]);
        return response()->json(['payment_status' => 'PENDING']);
    }
}

    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be cancelled.'], 400);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->product_variant_id) {
                    ProductVariant::where('id', $item->product_variant_id)
                        ->increment('stock', $item->quantity);
                }
            }
            $order->items()->delete();
            $order->delete();
        });

        return response()->json(['message' => 'Order cancelled and stock restored']);
    }

    private function sendTelegramPaymentConfirmed(Order $order): void
    {
        try {
            $token  = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID');
            if (!$token || !$chatId) return;

            $itemsList = $order->items->map(function ($item) {
                $name  = $item->product->name  ?? 'Unknown';
                $size  = $item->variant->size  ?? '';
                $color = $item->variant->color ?? '';
                return "• {$name} ({$size}/{$color}) x{$item->quantity} — \${$item->price}";
            })->join("\n");

            $customerName = $order->user->first_name
                ? $order->user->first_name . ' ' . $order->user->last_name
                : ($order->user->name ?? 'Unknown');

            $message = "✅ <b>Payment Confirmed — Order #{$order->id}</b>\n\n"
                     . "👤 Customer: {$customerName}\n"
                     . "📧 Email: {$order->user->email}\n\n"
                     . "📦 Items:\n{$itemsList}\n\n"
                     . "💰 Total: <b>\${$order->total_amount}</b>\n"
                     . "🕐 Time: " . now()->format('d M Y, h:i A');

            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => $message,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Telegram payment notification failed: ' . $e->getMessage());
        }
    }
}