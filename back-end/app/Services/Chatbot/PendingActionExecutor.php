<?php

namespace App\Services\Chatbot;

use App\Models\ChatPendingAction;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Validation\ValidationException;

/**
 * PendingActionExecutor - Thực thi các write action sau khi user xác nhận.
 * 
 * Gọi trực tiếp service layer thay vì qua HTTP để đảm bảo:
 * - Tốc độ nhanh
 * - Không cần auth middleware lại
 * - Dễ test
 * - Guard đã check ở controller
 *
 * Vì bỏ qua FormRequest nên executor PHẢI tự validate lại payload bằng đúng
 * rules của API công khai trước khi ghi — payload này do Gemini sinh ra, không
 * phải do người dùng gõ, nên không được tin.
 */
class PendingActionExecutor
{
    public function __construct(
        private CartService $cartService,
    ) {}

    /**
     * Thực thi action dựa trên type.
     *
     * @throws ValidationException
     * @throws \Exception
     */
    public function execute(ChatPendingAction $action, User $user): array
    {
        return match ($action->action_type) {
            'cart_add_item' => $this->executeCartAddItem($action, $user),
            default => throw new \InvalidArgumentException("Unknown action type: {$action->action_type}"),
        };
    }

    /**
     * Validate payload against Laravel rules before writing.
     *
     * @throws ValidationException
     */
    private function validatePayload(ChatPendingAction $action, array $rules, array $messages = [], array $attributes = []): array
    {
        return validator($action->payload, $rules, $messages, $attributes)->validate();
    }

    /**
     * Thêm món vào giỏ hàng.
     */
    private function executeCartAddItem(ChatPendingAction $action, User $user): array
    {
        // Mirror CartController's expectations. CartService re-checks availability
        // and availability locks inside its own transaction, so this is the first
        // of two passes — not the only one.
        $payload = $this->validatePayload(
            $action,
            [
                'menu_item_id' => ['bail', 'required', 'integer', 'exists:menu_items,id'],
                'quantity' => ['bail', 'required', 'integer', 'min:1', 'max:99'],
                'spicy_level' => ['nullable', 'integer', 'min:0', 'max:7'],
                'topping_ids' => ['nullable', 'array', 'max:20'],
                'topping_ids.*' => ['integer', 'exists:menu_items,id'],
                'note' => ['nullable', 'string', 'max:200'],
            ],
            messages: [
                'menu_item_id.exists' => 'Món này không còn tồn tại trên thực đơn.',
                'quantity.min' => 'Số lượng tối thiểu là 1 phần.',
                'quantity.max' => 'Số lượng tối đa là 99 phần.',
                'spicy_level.max' => 'Cấp cay hợp lệ từ 0 đến 7.',
                'topping_ids.*.exists' => 'Một số topping không hợp lệ.',
                'note.max' => 'Ghi chú không được vượt quá 200 ký tự.',
            ],
            attributes: [
                'menu_item_id' => 'món',
                'quantity' => 'số lượng',
                'spicy_level' => 'cấp cay',
                'note' => 'ghi chú',
            ],
        );

        $cartItem = $this->cartService->addItem($user, [
            'menu_item_id' => $payload['menu_item_id'],
            'quantity' => $payload['quantity'],
            'spicy_level' => $payload['spicy_level'] ?? 0,
            'topping_ids' => $payload['topping_ids'] ?? [],
            'note' => $payload['note'] ?? null,
        ]);

        return [
            'cart_item_id' => $cartItem->id,
            'menu_item_id' => $cartItem->menu_item_id,
            'quantity' => $cartItem->quantity,
            'spicy_level' => $cartItem->spicy_level,
        ];
    }
}
