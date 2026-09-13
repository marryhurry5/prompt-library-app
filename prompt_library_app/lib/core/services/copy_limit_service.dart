import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../logic/providers/pro_provider.dart';
import '../../presentation/screens/pro/pro_upgrade_screen.dart';
import '../constants/app_colors.dart';
import 'admob_service.dart';

class CopyLimitService {
  static const int maxFreeCopiesPerDay = 3;
  static const String _keyCopyDate = 'copy_limit_date';
  static const String _keyCopyCount = 'copy_limit_count';
  static const String _keyUnlimitedUntil = 'copy_unlimited_until_ms';

  static String _getTodayDateString() {
    final now = DateTime.now();
    return '${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
  }

  /// Checks if user can copy a prompt right now
  static Future<bool> canCopyPrompt(BuildContext context) async {
    // 1. PRO users always have unlimited copies
    final isPro = context.read<ProProvider>().isProUser;
    if (isPro) return true;

    final prefs = await SharedPreferences.getInstance();

    // 2. Check active 24-hour unlimited pass
    final unlimitedUntilMs = prefs.getInt(_keyUnlimitedUntil) ?? 0;
    if (DateTime.now().millisecondsSinceEpoch < unlimitedUntilMs) {
      return true;
    }

    // 3. Check daily free copies (reset on new calendar day)
    final today = _getTodayDateString();
    final savedDate = prefs.getString(_keyCopyDate) ?? '';

    if (savedDate != today) {
      await prefs.setString(_keyCopyDate, today);
      await prefs.setInt(_keyCopyCount, 0);
      return true;
    }

    final count = prefs.getInt(_keyCopyCount) ?? 0;
    return count < maxFreeCopiesPerDay;
  }

  /// Returns remaining free copies for today
  static Future<int> getRemainingCopies(BuildContext context) async {
    final isPro = context.read<ProProvider>().isProUser;
    if (isPro) return 999;

    final prefs = await SharedPreferences.getInstance();
    final unlimitedUntilMs = prefs.getInt(_keyUnlimitedUntil) ?? 0;
    if (DateTime.now().millisecondsSinceEpoch < unlimitedUntilMs) {
      return 999; // Unlimited
    }

    final today = _getTodayDateString();
    final savedDate = prefs.getString(_keyCopyDate) ?? '';
    if (savedDate != today) {
      return maxFreeCopiesPerDay;
    }

    final count = prefs.getInt(_keyCopyCount) ?? 0;
    return (maxFreeCopiesPerDay - count).clamp(0, maxFreeCopiesPerDay);
  }

  /// Increments copy count after successful copy
  static Future<void> registerCopy(BuildContext context) async {
    final isPro = context.read<ProProvider>().isProUser;
    if (isPro) return;

    final prefs = await SharedPreferences.getInstance();
    final unlimitedUntilMs = prefs.getInt(_keyUnlimitedUntil) ?? 0;
    if (DateTime.now().millisecondsSinceEpoch < unlimitedUntilMs) {
      return;
    }

    final today = _getTodayDateString();
    final savedDate = prefs.getString(_keyCopyDate) ?? '';

    int currentCount = 0;
    if (savedDate == today) {
      currentCount = prefs.getInt(_keyCopyCount) ?? 0;
    } else {
      await prefs.setString(_keyCopyDate, today);
    }

    await prefs.setInt(_keyCopyCount, currentCount + 1);
  }

  /// Activates 24-hour unlimited copy pass (e.g. after watching a rewarded ad)
  static Future<void> grant24HourPass() async {
    final prefs = await SharedPreferences.getInstance();
    final expiryMs = DateTime.now().add(const Duration(hours: 24)).millisecondsSinceEpoch;
    await prefs.setInt(_keyUnlimitedUntil, expiryMs);
  }

  /// Interactive dialog shown when user reaches the daily copy limit
  static void showCopyLimitDialog(
    BuildContext context, {
    required VoidCallback onUnlocked,
  }) {
    HapticFeedback.mediumImpact();

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        bool isLoadingAd = false;

        return StatefulBuilder(
          builder: (dialogContext, setState) {
            return AlertDialog(
              backgroundColor: AppColors.surface,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
              titlePadding: const EdgeInsets.fromLTRB(20, 24, 20, 10),
              contentPadding: const EdgeInsets.fromLTRB(20, 10, 20, 20),
              title: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: AppColors.locked.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Icon(Icons.lock_clock_rounded, color: AppColors.locked, size: 24),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Daily Limit Reached',
                      style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'You have used your 3 free prompt copies for today.',
                    style: TextStyle(color: AppColors.textPrimary, fontSize: 14, height: 1.4),
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: AppColors.primary.withOpacity(0.3)),
                    ),
                    child: Row(
                      children: const [
                        Icon(Icons.bolt_rounded, color: AppColors.primaryAccent, size: 22),
                        SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'Watch a quick 5-sec video ad to unlock UNLIMITED copies for 24 hours!',
                            style: TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),

                  // Option A: Watch Rewarded Ad
                  SizedBox(
                    width: double.infinity,
                    height: 48,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primary,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                      onPressed: isLoadingAd
                          ? null
                          : () {
                              setState(() => isLoadingAd = true);
                              final adService = AdMobService();
                              adService.loadRewardedAd(
                                onAdLoaded: () {
                                  adService.showRewardedAd(
                                    onRewardEarned: () async {
                                      await grant24HourPass();
                                      if (ctx.mounted) Navigator.pop(ctx);
                                      onUnlocked();
                                      HapticFeedback.mediumImpact();
                                      ScaffoldMessenger.of(context).showSnackBar(
                                        const SnackBar(
                                          content: Text('🎉 24-Hour Unlimited Copies Unlocked!'),
                                          backgroundColor: AppColors.unlocked,
                                        ),
                                      );
                                    },
                                    onAdDismissed: () {
                                      if (dialogContext.mounted) setState(() => isLoadingAd = false);
                                    },
                                    onError: (err) async {
                                      // Graceful fallback: grant unlock if ad network fails
                                      await grant24HourPass();
                                      if (ctx.mounted) Navigator.pop(ctx);
                                      onUnlocked();
                                    },
                                  );
                                },
                                onAdFailed: (err) async {
                                  // Graceful fallback
                                  await grant24HourPass();
                                  if (ctx.mounted) Navigator.pop(ctx);
                                  onUnlocked();
                                },
                              );
                            },
                      icon: isLoadingAd
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.play_circle_fill_rounded, color: Colors.white, size: 20),
                      label: Text(
                        isLoadingAd ? 'Loading Video Ad...' : 'Watch Ad to Unlock 24h',
                        style: const TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),

                  const SizedBox(height: 10),

                  // Option B: Upgrade to PRO
                  SizedBox(
                    width: double.infinity,
                    height: 46,
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        side: const BorderSide(color: AppColors.primaryAccent, width: 1.5),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                      onPressed: () {
                        Navigator.pop(ctx);
                        Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const ProUpgradeScreen()),
                        );
                      },
                      icon: const Icon(Icons.workspace_premium_rounded, color: AppColors.primaryAccent, size: 18),
                      label: const Text(
                        'Get PRO (₹29/mo) • Ad-Free',
                        style: TextStyle(color: AppColors.primaryAccent, fontSize: 13, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),

                  const SizedBox(height: 6),
                  Center(
                    child: TextButton(
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('Cancel', style: TextStyle(color: AppColors.textMuted, fontSize: 13)),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }
}
