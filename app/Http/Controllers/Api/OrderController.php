<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http; // ✅ Added for Telegram

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::with(['items.product', 'items.variant'])
            ->where('user_id', $request->user()->id)
            ->where(function ($q) {
                $q->where('status', '!=', 'pending')
                  ->orWhereNull('qr_string');
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Orders retrieved successfully',
            'orders'  => $orders,
        ]);
    }

    public function adminIndex()
    {
        $orders = Order::with(['user', 'items.product', 'items.variant'])
            ->where(function ($q) {
                $q->where('status', '!=', 'pending')
                  ->orWhereNull('qr_string');
            })
            ->latest()
            ->get();

        return response()->json([
            'message' => 'All orders retrieved successfully',
            'orders'  => $orders,
        ]);
    }

    public function show(Request $request, Order $order)
    {
        $isAdmin = $request->user()->role === 'admin';

        if (!$isAdmin && $order->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'message' => 'Order retrieved successfully',
            'order'   => $order->load(['user', 'items.product', 'items.variant']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.price'              => 'required|numeric|min:0',
            'total_amount'               => 'required|numeric|min:0',
        ]);

        try {
            $order = DB::transaction(function () use ($request) {
                foreach ($request->items as $item) {
                    $variant = ProductVariant::findOrFail($item['product_variant_id']);
                    if ($variant->stock < $item['quantity']) {
                        throw new \Exception(
                            "Insufficient stock for variant {$variant->id} ({$variant->size} {$variant->color})"
                        );
                    }
                }

                foreach ($request->items as $item) {
                    ProductVariant::where('id', $item['product_variant_id'])
                        ->decrement('stock', $item['quantity']);
                }

                $order = Order::create([
                    'user_id'      => $request->user()->id,
                    'total_amount' => $request->total_amount,
                    'status'       => 'pending',
                    'notes'        => $request->notes ?? null,
                ]);

                $order->items()->createMany(
                    array_map(fn($item) => [
                        'product_id'         => $item['product_id'],
                        'product_variant_id' => $item['product_variant_id'],
                        'quantity'           => $item['quantity'],
                        'price'              => $item['price'],
                    ], $request->items)
                );

                return $order;
            });

            // ✅ No Telegram here — wait until payment confirmed
            return response()->json([
                'message' => 'Order created successfully',
                'order'   => $order->load(['items.product', 'items.variant']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function updateStatus(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|in:pending,paid,shipped,delivered,cancelled',
        ]);

        $order->update(['status' => $request->status]);

        // ✅ Send Telegram when admin marks as paid, shipped, or delivered
        if (in_array($request->status, ['paid', 'shipped', 'delivered'])) {
            $order->load(['items.product', 'items.variant', 'user']);
            $this->sendTelegramNotification($order, $request->status);
        }

        return response()->json([
            'message' => 'Order status updated successfully',
            'order'   => $order->load(['items.product', 'items.variant']),
        ]);
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
            $order->load('items');

            foreach ($order->items as $item) {
                if ($item->product_variant_id) {
                    ProductVariant::where('id', $item->product_variant_id)
                        ->increment('stock', $item->quantity);
                }
            }

            $order->update(['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Order cancelled',
            'order'   => $order->fresh(),
        ]);
    }

    public function destroy(Order $order)
    {
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Only pending orders can be deleted.'], 400);
        }

        DB::transaction(function () use ($order) {
            $order->load('items');

            foreach ($order->items as $item) {
                if ($item->product_variant_id) {
                    ProductVariant::where('id', $item->product_variant_id)
                        ->increment('stock', $item->quantity);
                }
            }

            $order->items()->delete();
            $order->delete();
        });

        return response()->json(['message' => 'Order deleted successfully']);
    }

    // ✅ Telegram notification helper
    public function sendTelegramNotification(Order $order, string $status = 'paid'): void
    {
        try {
            $token  = env('TELEGRAM_BOT_TOKEN');
            $chatId = env('TELEGRAM_CHAT_ID');

            if (!$token || !$chatId) return;

            // Build items list
            $itemsList = $order->items->map(function ($item) {
                $name  = $item->product->name  ?? 'Unknown';
                $size  = $item->variant->size  ?? '';
                $color = $item->variant->color ?? '';
                return "• {$name} ({$size}/{$color}) x{$item->quantity} — \${$item->price}";
            })->join("\n");

            $customerName = $order->user->first_name
                ? $order->user->first_name . ' ' . $order->user->last_name
                : ($order->user->name ?? 'Unknown');

            // ✅ Different emoji and title per status
            $statusEmoji = match($status) {
                'paid'      => '✅',
                'shipped'   => '🚚',
                'delivered' => '📬',
                default     => '📋',
            };

            $statusLabel = match($status) {
                'paid'      => 'Payment Confirmed',
                'shipped'   => 'Order Shipped',
                'delivered' => 'Order Delivered',
                default     => 'Order Updated',
            };

            // ✅ Get customer's default address
            $address = $order->user->addresses()->where('is_default', true)->first()
                    ?? $order->user->addresses()->latest()->first();

            $addressLine = $address
                ? "{$address->first_name} {$address->last_name}\n"
                . "📍 {$address->address_line}, {$address->city}, {$address->country}\n"
                . "📞 {$address->phone}"
                : 'No address saved';

            $message = "{$statusEmoji} <b>{$statusLabel} — Order #{$order->id}</b>\n\n"
                    . "👤 Customer: {$customerName}\n"
                    . "📧 Email: {$order->user->email}\n\n"
                    . "📬 Delivery Address:\n{$addressLine}\n\n"
                    . "📦 Items:\n{$itemsList}\n\n"
                    . "💰 Total: <b>\${$order->total_amount}</b>\n"
                    . "🕐 Time: " . now()->format('d M Y, h:i A');

            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'    => $chatId,
                'parse_mode' => 'HTML',
                'text'       => $message,
            ]);

        } catch (\Exception $e) {
            \Log::warning('Telegram notification failed: ' . $e->getMessage());
        }
    }
}