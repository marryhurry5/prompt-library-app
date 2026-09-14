import 'package:flutter/material.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import '../constants/admob_ids.dart';
import '../constants/app_colors.dart';

class AdMobService {
  RewardedAd? _rewardedAd;
  bool _isAdLoading = false;
  int _retryCount = 0;
  static const int _maxRetries = 3;

  bool get isAdLoaded => _rewardedAd != null;
  bool get isAdLoading => _isAdLoading;

  /// Loads Google AdMob Live Rewarded Ad with automatic retry logic
  void loadRewardedAd({VoidCallback? onAdLoaded, Function(String)? onAdFailed}) {
    if (_isAdLoading || _rewardedAd != null) return;

    _isAdLoading = true;

    RewardedAd.load(
      adUnitId: AdMobIds.rewardedAdUnitId,
      request: const AdRequest(),
      rewardedAdLoadCallback: RewardedAdLoadCallback(
        onAdLoaded: (RewardedAd ad) {
          _rewardedAd = ad;
          _isAdLoading = false;
          _retryCount = 0;
          debugPrint('[AdMob] Live Rewarded Ad loaded successfully.');
          if (onAdLoaded != null) onAdLoaded();
        },
        onAdFailedToLoad: (LoadAdError error) {
          _rewardedAd = null;
          _isAdLoading = false;
          debugPrint('[AdMob] Live Rewarded Ad failed to load (attempt ${_retryCount + 1}): ${error.message} (code: ${error.code})');
          
          if (_retryCount < _maxRetries) {
            _retryCount++;
            Future.delayed(Duration(seconds: _retryCount * 2), () {
              loadRewardedAd(onAdLoaded: onAdLoaded, onAdFailed: onAdFailed);
            });
          } else {
            if (onAdFailed != null) onAdFailed(error.message);
          }
        },
      ),
    );
  }

  /// Displays the Live Rewarded Ad and invokes [onRewardEarned] upon user completing the ad
  void showRewardedAd({
    required BuildContext context,
    required VoidCallback onRewardEarned,
    VoidCallback? onAdDismissed,
  }) {
    if (_rewardedAd != null) {
      bool rewardEarned = false;

      _rewardedAd!.fullScreenContentCallback = FullScreenContentCallback(
        onAdDismissedFullScreenContent: (RewardedAd ad) {
          ad.dispose();
          _rewardedAd = null;
          if (rewardEarned) {
            onRewardEarned();
          }
          if (onAdDismissed != null) onAdDismissed();
          loadRewardedAd(); // Preload next live ad
        },
        onAdFailedToShowFullScreenContent: (RewardedAd ad, AdError error) {
          ad.dispose();
          _rewardedAd = null;
          debugPrint('[AdMob] Live Ad failed to show: ${error.message}');
          if (onAdDismissed != null) onAdDismissed();
          if (context.mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text('Ad display error: ${error.message}. Please try again.'),
                backgroundColor: AppColors.surface,
              ),
            );
          }
          loadRewardedAd();
        },
      );

      _rewardedAd!.show(
        onUserEarnedReward: (AdWithoutView ad, RewardItem reward) {
          debugPrint('[AdMob] User completed rewarded ad! Type: ${reward.type}, Amount: ${reward.amount}');
          rewardEarned = true;
        },
      );
    } else {
      // Ad is still fetching or not ready yet
      debugPrint('[AdMob] Live ad not ready yet. Attempting immediate fetch...');
      
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Row(
            children: [
              SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.primaryAccent),
              ),
              SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Loading live ad from Google AdMob... Tap again in a moment.',
                  style: TextStyle(color: Colors.white, fontSize: 13),
                ),
              ),
            ],
          ),
          duration: Duration(seconds: 2),
          backgroundColor: AppColors.surface,
        ),
      );

      loadRewardedAd(
        onAdLoaded: () {
          if (context.mounted) {
            ScaffoldMessenger.of(context).hideCurrentSnackBar();
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(
                content: Text('✅ Live Ad is ready! Tap "Watch Ad" to view.'),
                duration: Duration(seconds: 2),
                backgroundColor: AppColors.unlocked,
              ),
            );
          }
        },
        onAdFailed: (error) {
          if (context.mounted) {
            ScaffoldMessenger.of(context).hideCurrentSnackBar();
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text('Could not load live ad: $error. Please check connection.'),
                duration: const Duration(seconds: 3),
                backgroundColor: AppColors.surface,
              ),
            );
          }
        },
      );

      if (onAdDismissed != null) onAdDismissed();
    }
  }

  /// Helper to create and load a standard Banner Ad
  static BannerAd createBannerAd({
    required Function(Ad) onAdLoaded,
    required Function(Ad, LoadAdError) onAdFailedToLoad,
  }) {
    return BannerAd(
      adUnitId: AdMobIds.bannerAdUnitId,
      size: AdSize.banner,
      request: const AdRequest(),
      listener: BannerAdListener(
        onAdLoaded: onAdLoaded,
        onAdFailedToLoad: onAdFailedToLoad,
      ),
    );
  }

  void dispose() {
    _rewardedAd?.dispose();
    _rewardedAd = null;
  }
}
