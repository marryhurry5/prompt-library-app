import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import '../constants/admob_ids.dart';
import '../../presentation/widgets/test_ad_dialog.dart';

class AdMobService {
  RewardedAd? _rewardedAd;
  bool _isAdLoading = false;
  int _retryCount = 0;
  static const int _maxRetries = 3;

  bool get isAdLoaded => _rewardedAd != null;
  bool get isAdLoading => _isAdLoading;

  /// Loads Google AdMob Rewarded Ad with automatic retry logic
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
          debugPrint('[AdMob] Rewarded Ad loaded successfully.');
          if (onAdLoaded != null) onAdLoaded();
        },
        onAdFailedToLoad: (LoadAdError error) {
          _rewardedAd = null;
          _isAdLoading = false;
          debugPrint('[AdMob] Rewarded Ad failed to load (attempt ${_retryCount + 1}): ${error.message}');
          
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

  /// Displays Google AdMob Test Rewarded Ad, or falls back to full-screen TestAdDialog.
  /// Guaranteed: Content will NEVER unlock directly without showing a 5-second test ad.
  void showRewardedAdWithGuaranteedDisplay({
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
          loadRewardedAd(); // Preload next test ad
        },
        onAdFailedToShowFullScreenContent: (RewardedAd ad, AdError error) {
          ad.dispose();
          _rewardedAd = null;
          debugPrint('[AdMob] Failed to display ad: ${error.message}. Launching Test Ad Dialog.');
          if (context.mounted) {
            TestAdDialog.show(
              context,
              onRewardEarned: onRewardEarned,
              onDismissed: onAdDismissed,
            );
          }
          loadRewardedAd();
        },
      );

      _rewardedAd!.show(
        onUserEarnedReward: (AdWithoutView ad, RewardItem reward) {
          debugPrint('[AdMob] Reward verified! Type: ${reward.type}, Amount: ${reward.amount}');
          rewardEarned = true;
        },
      );
    } else {
      // If AdMob SDK is not preloaded yet or device is offline, open the 5-sec interactive Test Ad Dialog
      debugPrint('[AdMob] Preloaded ad not found. Launching guaranteed 5-second Test Ad Dialog.');
      if (context.mounted) {
        TestAdDialog.show(
          context,
          onRewardEarned: onRewardEarned,
          onDismissed: onAdDismissed,
        );
      }
      loadRewardedAd(); // Preload for next time
    }
  }

  /// Legacy showRewardedAd with safe fallback to avoid direct unlock
  void showRewardedAd({
    required VoidCallback onRewardEarned,
    VoidCallback? onAdDismissed,
    Function(String)? onError,
    BuildContext? context,
  }) {
    if (context != null) {
      showRewardedAdWithGuaranteedDisplay(
        context: context,
        onRewardEarned: onRewardEarned,
        onAdDismissed: onAdDismissed,
      );
      return;
    }

    if (_rewardedAd == null) {
      debugPrint('[AdMob] Warning: Ad not ready and no context provided for test ad dialog.');
      if (onError != null) onError('Test ad not ready. Please try again.');
      loadRewardedAd();
      return;
    }

    bool earned = false;
    _rewardedAd!.fullScreenContentCallback = FullScreenContentCallback(
      onAdDismissedFullScreenContent: (RewardedAd ad) {
        ad.dispose();
        _rewardedAd = null;
        if (earned) onRewardEarned();
        if (onAdDismissed != null) onAdDismissed();
        loadRewardedAd();
      },
      onAdFailedToShowFullScreenContent: (RewardedAd ad, AdError error) {
        ad.dispose();
        _rewardedAd = null;
        debugPrint('[AdMob] Failed to show ad: ${error.message}');
        if (onError != null) onError(error.message);
      },
    );

    _rewardedAd!.show(
      onUserEarnedReward: (AdWithoutView ad, RewardItem reward) {
        earned = true;
      },
    );
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
