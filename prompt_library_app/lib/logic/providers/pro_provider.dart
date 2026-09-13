import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ProProvider extends ChangeNotifier {
  bool _isProUser = false;
  int _proExpiresAtMs = 0;

  bool get isProUser => _isProUser;
  int get proExpiresAtMs => _proExpiresAtMs;

  int get daysRemaining {
    if (!_isProUser || _proExpiresAtMs == 0) return 0;
    final diff = DateTime.fromMillisecondsSinceEpoch(_proExpiresAtMs).difference(DateTime.now());
    return diff.inDays.clamp(0, 365);
  }

  ProProvider() {
    _loadProStatus();
  }

  Future<void> _loadProStatus() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _proExpiresAtMs = prefs.getInt('pro_expires_at_ms') ?? 0;
      final isProSaved = prefs.getBool('is_pro_user') ?? false;

      // Check if subscription has expired
      if (isProSaved) {
        if (_proExpiresAtMs > 0 && DateTime.now().millisecondsSinceEpoch > _proExpiresAtMs) {
          _isProUser = false;
          await prefs.setBool('is_pro_user', false);
        } else {
          _isProUser = true;
        }
      } else {
        _isProUser = false;
      }

      notifyListeners();
    } catch (e) {
      debugPrint('[ProProvider] Error loading status: $e');
    }
  }

  Future<void> activatePro({int durationDays = 30}) async {
    _isProUser = true;
    _proExpiresAtMs = DateTime.now().add(Duration(days: durationDays)).millisecondsSinceEpoch;

    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('is_pro_user', true);
    await prefs.setInt('pro_expires_at_ms', _proExpiresAtMs);
    notifyListeners();
  }

  Future<void> deactivatePro() async {
    _isProUser = false;
    _proExpiresAtMs = 0;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('is_pro_user', false);
    await prefs.remove('pro_expires_at_ms');
    notifyListeners();
  }
}
