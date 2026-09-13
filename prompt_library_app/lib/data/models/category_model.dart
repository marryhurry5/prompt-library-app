import 'package:flutter/material.dart';

class CategoryModel {
  final String name;
  final IconData icon;
  final Color color;

  const CategoryModel({
    required this.name,
    required this.icon,
    required this.color,
  });

  factory CategoryModel.fromName(String name) {
    IconData icon = Icons.folder_open_rounded;
    Color color = const Color(0xFF6366F1);

    final lower = name.toLowerCase();
    if (lower == 'all') {
      icon = Icons.grid_view_rounded;
      color = const Color(0xFF6C5CE7);
    } else if (lower.contains('photo') || lower.contains('edit')) {
      icon = Icons.photo_filter_rounded;
      color = const Color(0xFF00B894);
    } else if (lower.contains('art') || lower.contains('draw') || lower.contains('paint')) {
      icon = Icons.palette_rounded;
      color = const Color(0xFFE84393);
    } else if (lower.contains('gpt') || lower.contains('chat') || lower.contains('ai')) {
      icon = Icons.psychology_rounded;
      color = const Color(0xFF0984E3);
    } else if (lower.contains('thumb') || lower.contains('banner')) {
      icon = Icons.crop_original_rounded;
      color = const Color(0xFFFDCB6E);
    } else if (lower.contains('game') || lower.contains('gaming')) {
      icon = Icons.sports_esports_rounded;
      color = const Color(0xFF6C5CE7);
    } else if (lower.contains('write') || lower.contains('text') || lower.contains('content')) {
      icon = Icons.create_rounded;
      color = const Color(0xFF00CEC9);
    } else if (lower.contains('mockup') || lower.contains('product') || lower.contains('3d')) {
      icon = Icons.view_in_ar_rounded;
      color = const Color(0xFFD63031);
    } else if (lower.contains('ad') || lower.contains('market') || lower.contains('promote')) {
      icon = Icons.campaign_rounded;
      color = const Color(0xFFE17055);
    } else if (lower.contains('video')) {
      icon = Icons.video_library_rounded;
      color = const Color(0xFF8E44AD);
    }

    return CategoryModel(name: name, icon: icon, color: color);
  }

  static const List<CategoryModel> defaultCategories = [
    CategoryModel(name: 'All', icon: Icons.grid_view_rounded, color: Color(0xFF6C5CE7)),
    CategoryModel(name: 'Photo Editing', icon: Icons.photo_filter_rounded, color: Color(0xFF00B894)),
    CategoryModel(name: 'AI Art', icon: Icons.palette_rounded, color: Color(0xFFE84393)),
    CategoryModel(name: 'ChatGPT Prompt', icon: Icons.psychology_rounded, color: Color(0xFF0984E3)),
    CategoryModel(name: 'Thumbnail', icon: Icons.crop_original_rounded, color: Color(0xFFFDCB6E)),
    CategoryModel(name: 'Gaming Banner', icon: Icons.sports_esports_rounded, color: Color(0xFF6C5CE7)),
    CategoryModel(name: 'Content Writing', icon: Icons.create_rounded, color: Color(0xFF00CEC9)),
    CategoryModel(name: 'Product Mockup', icon: Icons.view_in_ar_rounded, color: Color(0xFFD63031)),
    CategoryModel(name: 'Ad Copy', icon: Icons.campaign_rounded, color: Color(0xFFE17055)),
    CategoryModel(name: 'Video Editing', icon: Icons.video_library_rounded, color: Color(0xFF6C5CE7)),
    CategoryModel(name: 'Marketing', icon: Icons.trending_up_rounded, color: Color(0xFF00B894)),
  ];
}
