// ============================================================
// 🛒 CART MODELS — phản ánh response từ /api/cart
// ============================================================
//
// Backend trả:
//   { id, user_id, version, items: [{ id, menu_item_id, menu_item,
//     quantity, spicy_level, note, toppings: [{id, name, price}],
//     topping_ids, unit_price, topping_price, subtotal }],
//     total_items, total_quantity, subtotal }
//
// ============================================================

import '../../../core/api/json_number.dart';

class CartItemTopping {
  CartItemTopping({required this.id, required this.name, required this.price});

  final int id;
  final String name;
  final double price;

  factory CartItemTopping.fromJson(Map<String, dynamic> json) =>
      CartItemTopping(
        id: json['id'] as int,
        name: (json['name'] ?? '').toString(),
        price: jsonPrice(json['price'], 'cart.topping.price'),
      );
}

class CartItem {
  CartItem({
    required this.id,
    required this.menuItemId,
    required this.menuItemName,
    required this.menuItemImageUrl,
    required this.quantity,
    required this.spicyLevel,
    this.note,
    required this.toppings,
    required this.toppingIds,
    required this.unitPrice,
    required this.toppingPrice,
    required this.subtotal,
  });

  final int id;
  final int menuItemId;
  final String menuItemName;
  final String menuItemImageUrl;
  final int quantity;
  final int spicyLevel;
  final String? note;
  final List<CartItemTopping> toppings;
  final List<int> toppingIds;
  final double unitPrice;
  final double toppingPrice;
  final double subtotal;

  factory CartItem.fromJson(Map<String, dynamic> json) {
    final menu = (json['menu_item'] ?? json) as Map<String, dynamic>;
    final toppings =
        (json['toppings'] as List?)
            ?.map((t) => CartItemTopping.fromJson(t as Map<String, dynamic>))
            .toList() ??
        <CartItemTopping>[];
    final toppingIds =
        (json['topping_ids'] as List?)
            ?.map((t) => (t as num).toInt())
            .toList() ??
        <int>[];

    return CartItem(
      id: json['id'] as int,
      menuItemId:
          (json['menu_item_id'] as num?)?.toInt() ?? (menu['id'] as int),
      menuItemName: (menu['name'] ?? '').toString(),
      menuItemImageUrl: (menu['image_url'] ?? '').toString(),
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      spicyLevel: (json['spicy_level'] as num?)?.toInt() ?? 0,
      note: json['note']?.toString(),
      toppings: toppings,
      toppingIds: toppingIds,
      unitPrice: jsonPrice(json['unit_price'], 'cart.unit_price'),
      toppingPrice: jsonPrice(json['topping_price'], 'cart.topping_price'),
      subtotal: jsonPrice(json['subtotal'], 'cart.item.subtotal'),
    );
  }
}

class Cart {
  Cart({
    required this.id,
    required this.version,
    required this.items,
    required this.totalItems,
    required this.totalQuantity,
    required this.subtotal,
  });

  final int id;
  final int version;
  final List<CartItem> items;
  final int totalItems;
  final int totalQuantity;
  final double subtotal;

  bool get isEmpty => items.isEmpty;
  bool get isNotEmpty => items.isNotEmpty;

  factory Cart.fromJson(Map<String, dynamic> json) {
    final items =
        (json['items'] as List?)
            ?.map((i) => CartItem.fromJson(i as Map<String, dynamic>))
            .toList() ??
        <CartItem>[];

    return Cart(
      id: (json['id'] as num?)?.toInt() ?? 0,
      version: (json['version'] as num?)?.toInt() ?? 0,
      items: items,
      totalItems: (json['total_items'] as num?)?.toInt() ?? items.length,
      totalQuantity:
          (json['total_quantity'] as num?)?.toInt() ??
          items.fold(0, (s, i) => s + i.quantity),
      subtotal: json['subtotal'] == null
          ? items.fold(0.0, (s, i) => s + i.subtotal)
          : jsonPrice(json['subtotal'], 'cart.subtotal'),
    );
  }
}
