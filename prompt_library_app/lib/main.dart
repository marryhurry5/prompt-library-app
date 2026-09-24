import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:google_fonts/google_fonts.dart';

import 'core/constants/app_colors.dart';
import 'logic/providers/favorites_provider.dart';
import 'logic/providers/pro_provider.dart';
import 'logic/providers/prompt_provider.dart';
import 'logic/providers/search_provider.dart';
import 'logic/providers/shop_provider.dart';
import 'logic/providers/user_provider.dart';
import 'presentation/screens/navigation/main_navigation_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  // Transparent status bar and themed system navigation bar for immersive dark UI
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: AppColors.background,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  // Render the app UI instantly (<100ms) without waiting for ad network handshakes
  runApp(const AiPromptLibraryApp());

  // Initialize Google Mobile Ads SDK asynchronously in the background
  unawaited(MobileAds.instance.initialize());
}

class AiPromptLibraryApp extends StatelessWidget {
  const AiPromptLibraryApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => PromptProvider()),
        ChangeNotifierProvider(create: (_) => SearchProvider()),
        ChangeNotifierProvider(create: (_) => FavoritesProvider()),
        ChangeNotifierProvider(create: (_) => ShopProvider()),
        ChangeNotifierProvider(create: (_) => ProProvider()),
        ChangeNotifierProvider(create: (_) => UserProvider()),
      ],
      child: MaterialApp(
        title: 'AI Prompt Library',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          useMaterial3: true,
          brightness: Brightness.dark,
          scaffoldBackgroundColor: AppColors.background,
          colorScheme: ColorScheme.fromSeed(
            seedColor: AppColors.primary,
            brightness: Brightness.dark,
            background: AppColors.background,
            surface: AppColors.surface,
          ),
          textTheme: GoogleFonts.outfitTextTheme(
            ThemeData.dark().textTheme,
          ),
        ),
        home: const MainNavigationScreen(),
      ),
    );
  }
}
