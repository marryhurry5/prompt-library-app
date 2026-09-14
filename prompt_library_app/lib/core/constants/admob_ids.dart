import 'dart:io';

class AdMobIds {
  // Official Google AdMob Test Rewarded Ad Unit IDs
  static const String _androidTestRewardedId = 'ca-app-pub-3940256099942544/5224354917';
  static const String _iosTestRewardedId = 'ca-app-pub-3940256099942544/1712485313';

  // Google AdMob Test Banner Ad Unit IDs
  static const String _androidTestBannerId = 'ca-app-pub-3940256099942544/6300978111';
  static const String _iosTestBannerId = 'ca-app-pub-3940256099942544/2934735716';

  static String get rewardedAdUnitId {
    if (Platform.isAndroid) {
      return _androidTestRewardedId;
    } else if (Platform.isIOS) {
      return _iosTestRewardedId;
    } else {
      return _androidTestRewardedId;
    }
  }

  static String get bannerAdUnitId {
    if (Platform.isAndroid) {
      return _androidTestBannerId;
    } else if (Platform.isIOS) {
      return _iosTestBannerId;
    } else {
      return _androidTestBannerId;
    }
  }
}
