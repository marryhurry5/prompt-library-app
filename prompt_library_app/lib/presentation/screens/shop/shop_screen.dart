import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/services/admob_service.dart';
import '../../../data/models/shop_item_model.dart';
import '../../../logic/providers/shop_provider.dart';
import '../admin/admin_auth_screen.dart';
import '../admin/admin_shop_screen.dart';

class ShopScreen extends StatefulWidget {
  const ShopScreen({super.key});

  @override
  State<ShopScreen> createState() => _ShopScreenState();
}

class _ShopScreenState extends State<ShopScreen> {
  late AdMobService _adMobService;
  bool _isAdLoading = false;

  @override
  void initState() {
    super.initState();
    _adMobService = AdMobService();
    _adMobService.loadRewardedAd();
  }

  @override
  void dispose() {
    _adMobService.dispose();
    super.dispose();
  }

  void _navigateToAdmin(BuildContext context) {
    final shopProvider = context.read<ShopProvider>();
    if (shopProvider.isAdminLoggedIn) {
      Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const AdminShopScreen()),
      );
    } else {
      Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const AdminAuthScreen()),
      );
    }
  }

  /// Secret Admin Access Dialog
  void _openAdminAccessDialog() {
    final TextEditingController pinController = TextEditingController();

    showDialog(
      context: context,
      builder: (dialogCtx) {
        return AlertDialog(
          backgroundColor: AppColors.surface,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
          title: Row(
            children: const [
              Icon(Icons.security_rounded, color: AppColors.primary, size: 24),
              SizedBox(width: 8),
              Text(
                'Admin Mode Access',
                style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          content: TextField(
            controller: pinController,
            keyboardType: TextInputType.number,
            obscureText: true,
            autofocus: true,
            style: const TextStyle(color: Colors.white, fontSize: 18, letterSpacing: 4),
            decoration: InputDecoration(
              hintText: 'Enter Admin PIN (7777)',
              hintStyle: const TextStyle(color: AppColors.textMuted, fontSize: 13, letterSpacing: 0),
              filled: true,
              fillColor: AppColors.surfaceLight,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogCtx),
              child: const Text('Cancel', style: TextStyle(color: AppColors.textMuted)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () {
                final pin = pinController.text.trim();
                Navigator.pop(dialogCtx);
                if (pin == '7777' || pin == '1234') {
                  Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => const AdminShopScreen()),
                  );
                } else {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Incorrect Admin PIN'),
                      backgroundColor: Colors.redAccent,
                    ),
                  );
                }
              },
              child: const Text('Unlock Admin', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            ),
          ],
        );
      },
    );
  }

  /// Triggers Rewarded Ad to unlock digital product
  void _watchAdToUnlock(ShopItemModel item) {
    setState(() => _isAdLoading = true);

    _adMobService.showRewardedAd(
      context: context,
      onRewardEarned: () {
        if (mounted) {
          setState(() => _isAdLoading = false);
          context.read<ShopProvider>().unlockItem(item.id);
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Row(
                children: const [
                  Icon(Icons.check_circle_rounded, color: AppColors.unlocked, size: 20),
                  SizedBox(width: 10),
                  Text('🎉 Bundle unlocked! Tap to open access link.'),
                ],
              ),
              backgroundColor: AppColors.surfaceLight,
            ),
          );
        }
      },
      onAdDismissed: () {
        if (mounted) setState(() => _isAdLoading = false);
      },
    );
  }

  Future<void> _openAccessLink(String url) async {
    try {
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Could not open link: $url')),
          );
        }
      }
    } catch (e) {
      debugPrint('[ShopScreen] Error opening link: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final shopProvider = context.watch<ShopProvider>();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: Row(
          children: const [
            Icon(Icons.shopping_bag_rounded, color: AppColors.primary, size: 22),
            SizedBox(width: 8),
            Text(
              'Reel Bundles & Shop',
              style: TextStyle(color: AppColors.textPrimary, fontSize: 18, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.admin_panel_settings_rounded, color: AppColors.primaryAccent),
            tooltip: 'Admin Portal',
            onPressed: () => _navigateToAdmin(context),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: () => shopProvider.fetchShopItems(),
        child: shopProvider.isLoading
            ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
            : shopProvider.items.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: const [
                        Icon(Icons.storefront_rounded, color: AppColors.textMuted, size: 60),
                        SizedBox(height: 16),
                        Text(
                          'Digital Shop Coming Soon',
                          style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                        ),
                        SizedBox(height: 6),
                        Text(
                          'Reel bundles and PDFs will appear here.',
                          style: TextStyle(color: AppColors.textSecondary, fontSize: 13),
                        ),
                      ],
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 150),
                    itemCount: shopProvider.items.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 16),
                    itemBuilder: (context, index) {
                      final item = shopProvider.items[index];
                      final isUnlocked = shopProvider.isItemUnlocked(item.id);

                      return Container(
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: isUnlocked ? AppColors.unlocked : AppColors.border,
                            width: 1.5,
                          ),
                          boxShadow: const [
                            BoxShadow(color: Colors.black26, blurRadius: 10, offset: Offset(0, 4)),
                          ],
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            // Cover Image
                            Stack(
                              children: [
                                AspectRatio(
                                  aspectRatio: 16 / 9,
                                  child: CachedNetworkImage(
                                    imageUrl: item.imageUrl,
                                    fit: BoxFit.cover,
                                    placeholder: (_, __) => Container(color: AppColors.surfaceLight),
                                    errorWidget: (_, __, ___) => Container(
                                      color: AppColors.surfaceLight,
                                      child: const Icon(Icons.video_collection_rounded, color: AppColors.primary, size: 50),
                                    ),
                                  ),
                                ),
                                Positioned(
                                  top: 10,
                                  left: 10,
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                    decoration: BoxDecoration(
                                      color: AppColors.primary,
                                      borderRadius: BorderRadius.circular(20),
                                    ),
                                    child: Text(
                                      item.itemType,
                                      style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                                    ),
                                  ),
                                ),
                              ],
                            ),

                            // Details
                            Padding(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    item.title,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 16,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                  if (item.description.isNotEmpty) ...[
                                    const SizedBox(height: 6),
                                    Text(
                                      item.description,
                                      style: const TextStyle(color: AppColors.textSecondary, fontSize: 13),
                                    ),
                                  ],

                                  const SizedBox(height: 16),

                                  // Action Button (Watch Ad vs. Open Link)
                                  SizedBox(
                                    width: double.infinity,
                                    height: 48,
                                    child: isUnlocked
                                        ? ElevatedButton.icon(
                                            style: ElevatedButton.styleFrom(
                                              backgroundColor: AppColors.unlocked,
                                              foregroundColor: Colors.black,
                                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                                            ),
                                            onPressed: () => _openAccessLink(item.accessLink),
                                            icon: const Icon(Icons.download_rounded, color: Colors.black),
                                            label: const Text(
                                              'Open PDF / Access Link 🚀',
                                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                            ),
                                          )
                                        : ElevatedButton.icon(
                                            style: ElevatedButton.styleFrom(
                                              backgroundColor: AppColors.locked,
                                              foregroundColor: Colors.black,
                                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                                            ),
                                            onPressed: _isAdLoading ? null : () => _watchAdToUnlock(item),
                                            icon: _isAdLoading
                                                ? const SizedBox(
                                                    width: 18,
                                                    height: 18,
                                                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black),
                                                  )
                                                : const Icon(Icons.play_circle_fill_rounded, color: Colors.black),
                                            label: Text(
                                              _isAdLoading ? 'Loading Ad...' : 'Watch Ad to Unlock',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                            ),
                                          ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      );
                    },
                  ),
      ),
    );
  }
}
