class ShopItemModel {
  final int id;
  final String title;
  final String description;
  final String imageUrl;
  final String accessLink;
  final String itemType;
  final String createdAt;

  ShopItemModel({
    required this.id,
    required this.title,
    required this.description,
    required this.imageUrl,
    required this.accessLink,
    required this.itemType,
    required this.createdAt,
  });

  factory ShopItemModel.fromJson(Map<String, dynamic> json) {
    return ShopItemModel(
      id: int.tryParse(json['id'].toString()) ?? 0,
      title: json['title'] ?? '',
      description: json['description'] ?? '',
      imageUrl: json['image_url'] ?? '',
      accessLink: json['access_link'] ?? '',
      itemType: json['item_type'] ?? 'Reel Bundle',
      createdAt: json['created_at'] ?? '',
    );
  }
}
