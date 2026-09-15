import 'package:flutter/material.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import 'package:provider/provider.dart';
import '../../core/constants/app_colors.dart';
import '../../core/services/admob_service.dart';
import '../../logic/providers/pro_provider.dart';

class BannerAdWidget extends StatefulWidget {
  const BannerAdWidget({super.key});

  @override
  State<BannerAdWidget> createState() => _BannerAdWidgetState();
}

class _BannerAdWidgetState extends State<BannerAdWidget> {
  BannerAd? _bannerAd;
  bool _isAdLoaded = false;

  @override
  void initState() {
    super.initState();
    _loadBannerAd();
  }

  void _loadBannerAd() {
    _bannerAd = AdMobService.createBannerAd(
      onAdLoaded: (ad) {
        if (mounted) {
          setState(() {
            _isAdLoaded = true;
          });
        }
      },
      onAdFailedToLoad: (ad, error) {
        debugPrint('[AdMob] Banner ad failed to load: ${error.message}');
        ad.dispose();
      },
    );
    _bannerAd?.load();
  }

  @override
  void dispose() {
    _bannerAd?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final proProvider = context.watch<ProProvider>();
    if (proProvider.isProUser) {
      return const SizedBox.shrink(); // Hide ads for Pro members!
    }

    if (!_isAdLoaded || _bannerAd == null) {
      return const SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      height: _bannerAd!.size.height.toDouble(),
      decoration: BoxDecoration(
        color: AppColors.surface.withOpacity(0.95),
        border: Border(
          top: BorderSide(color: Colors.white.withOpacity(0.08), width: 0.8),
        ),
      ),
      alignment: Alignment.center,
      child: AdWidget(ad: _bannerAd!),
    );
  }
}
