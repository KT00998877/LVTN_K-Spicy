<?php

namespace App\Services\Chatbot;

use App\Models\MenuItem;

/**
 * ConfirmationSummaryBuilder - Xây dựng summary JSON cho ConfirmationCard.
 * 
 * Summary chứa thông tin hiển thị cho user xác nhận,
 * được lưu trong chat_pending_actions.summary.
 */
class ConfirmationSummaryBuilder
{
    /**
     * Xây dựng summary cho cart_add_item.
     */
    public function buildCartAddItem(array $args): array
    {
        $menuItem = isset($args['menu_item_id']) 
            ? MenuItem::find($args['menu_item_id']) 
            : null;

        $quantity = $args['quantity'] ?? 1;
        $spicyLevel = $args['spicy_level'] ?? 0;
        $toppingIds = $args['topping_ids'] ?? [];
        
        // Calculate total price
        $unitPrice = $menuItem ? (float) $menuItem->price : 0;
        $toppingPrice = 0;
        $toppings = [];

        if (!empty($toppingIds)) {
            $toppingItems = MenuItem::whereIn('id', $toppingIds)->get();
            foreach ($toppingItems as $topping) {
                $toppingPrice += (float) $topping->price;
                $toppings[] = $topping->name;
            }
        }

        $perItemPrice = $unitPrice + $toppingPrice;
        $subtotal = $perItemPrice * $quantity;

        // Spicy level display
        $spicyDisplay = $this->getSpicyDisplay($spicyLevel);

        return [
            'type' => 'cart_add_item',
            'icon' => '🍜',
            'title' => 'Thêm vào giỏ hàng',
            'items' => [
                [
                    'label' => 'Món',
                    'value' => $menuItem ? $menuItem->name : "ID: {$args['menu_item_id']}",
                ],
                [
                    'label' => 'Số lượng',
                    'value' => "{$quantity} phần",
                ],
                [
                    'label' => 'Cấp cay',
                    'value' => $spicyDisplay,
                ],
            ],
            'price_breakdown' => [
                'unit_price' => $unitPrice,
                'topping_price' => $toppingPrice,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ],
            'toppings' => $toppings,
        ];
    }

    /**
     * Xây dựng summary dựa trên action_type.
     */
    public function build(string $actionType, array $args): array
    {
        return match ($actionType) {
            'cart_add_item' => $this->buildCartAddItem($args),
            default => [
                'type' => $actionType,
                'icon' => '❓',
                'title' => 'Xác nhận thao tác',
                'items' => [],
            ],
        };
    }

    /**
     * Lấy display text cho spicy level.
     */
    private function getSpicyDisplay(int $level): string
    {
        if ($level <= 0) {
            return 'Không cay';
        }

        $emoji = str_repeat('🌶', min($level, 5));
        return "{$emoji} Cấp {$level}";
    }
}