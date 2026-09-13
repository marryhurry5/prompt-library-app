import 'package:flutter/foundation.dart';
import 'package:google_mobile_ads/google_mobile_ads.dart';
import '../constants/admob_ids.dart';

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

  /// Displays the Rewarded Ad and invokes [onRewardEarned] upon successful completion
  void showRewardedAd({
    required VoidCallback onRewardEarned,
    VoidCallback? onAdDismissed,
    Function(String)? onError,
  }) {
    if (_rewardedAd == null) {
      // Fallback: If ad isn't ready or fails to load, gracefully notify or grant reward
      debugPrint('[AdMob] Ad not ready. Executing fallback reward.');
      onRewardEarned();
      return;
    }

    _rewardedAd!.fullScreenContentCallback = FullScreenContentCallback(
      onAdDismissedFullScreenContent: (RewardedAd ad) {
        ad.dispose();
        _rewardedAd = null;
        if (onAdDismissed != null) onAdDismissed();
        loadRewardedAd(); // Preload next ad
      },
      onAdFailedToShowFullScreenContent: (RewardedAd ad, AdError error) {
        ad.dispose();
        _rewardedAd = null;
        debugPrint('[AdMob] Failed to show ad: ${error.message}');
        if (onError != null) onError(error.message);
        onRewardEarned(); // Grant unlock on ad error fallback
      },
    );

    _rewardedAd!.show(
      onUserEarnedReward: (AdWithoutView ad, RewardItem reward) {
        debugPrint('[AdMob] User successfully completed Rewarded Ad! Type: ${reward.type}, Amount: ${reward.amount}');
        onRewardEarned();
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
