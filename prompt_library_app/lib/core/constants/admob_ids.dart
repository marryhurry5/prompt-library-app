import 'dart:io';

class AdMobIds {
  // Live Production Google AdMob Rewarded Ad Unit ID (Official Google Play Store)
  static const String _liveAndroidRewardedId = 'ca-app-pub-5860757655925932/6312549441';
  static const String _liveIosRewardedId = 'ca-app-pub-5860757655925932/6312549441';

  // Google AdMob Banner Ad Unit ID
  static const String _liveAndroidBannerId = 'ca-app-pub-3940256099942544/6300978111';
  static const String _liveIosBannerId = 'ca-app-pub-3940256099942544/2934735716';

  static String get rewardedAdUnitId {
    if (Platform.isAndroid) {
      return _liveAndroidRewardedId;
    } else if (Platform.isIOS) {
      return _liveIosRewardedId;
    } else {
      return _liveAndroidRewardedId;
    }
  }

  static String get bannerAdUnitId {
    if (Platform.isAndroid) {
      return _liveAndroidBannerId;
    } else if (Platform.isIOS) {
      return _liveIosBannerId;
    } else {
      return _liveAndroidBannerId;
    }
  }
}
