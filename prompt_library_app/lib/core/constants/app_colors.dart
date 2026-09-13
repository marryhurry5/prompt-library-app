import 'package:flutter/material.dart';

class AppColors {
  // Primary Palette
  static const Color primary = Color(0xFF6C5CE7); // Deep Vibrant Purple
  static const Color primaryAccent = Color(0xFFA29BFE);
  static const Color accent = Color(0xFFA29BFE); // Alias for primaryAccent
  static const Color secondary = Color(0xFF00B894); // Emerald Green Accent
  
  // Background Colors (Dark Mode First)
  static const Color background = Color(0xFF0F0F1A); // Ultra Dark Navy
  static const Color surface = Color(0xFF1A1A2E); // Dark Card Surface
  static const Color surfaceLight = Color(0xFF252542); // Elevated Container
  
  // Status & Badges
  static const Color trending = Color(0xFFFF7675); // Coral Red for Trending
  static const Color locked = Color(0xFFFDCB6E); // Amber Gold for Locked Prompts
  static const Color unlocked = Color(0xFF55E6C1); // Mint Green
  
  // Text Colors
  static const Color textPrimary = Color(0xFFFFFFFF);
  static const Color textSecondary = Color(0xFFA0A0C0);
  static const Color textMuted = Color(0xFF6C6C8A);
  
  // Card Borders & Dividers
  static const Color border = Color(0xFF2D2D4A);
  static const Color glassBorder = Color(0x33FFFFFF);
}
