import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../../core/constants/app_colors.dart';

class TestAdDialog extends StatefulWidget {
  final VoidCallback onRewardEarned;
  final VoidCallback? onDismissed;

  const TestAdDialog({
    super.key,
    required this.onRewardEarned,
    this.onDismissed,
  });

  static Future<void> show(
    BuildContext context, {
    required VoidCallback onRewardEarned,
    VoidCallback? onDismissed,
  }) {
    return showDialog(
      context: context,
      barrierDismissible: false,
      barrierColor: Colors.black.withOpacity(0.92),
      builder: (_) => TestAdDialog(
        onRewardEarned: onRewardEarned,
        onDismissed: onDismissed,
      ),
    );
  }

  @override
  State<TestAdDialog> createState() => _TestAdDialogState();
}

class _TestAdDialogState extends State<TestAdDialog> with SingleTickerProviderStateMixin {
  static const int _totalSeconds = 5;
  int _remainingSeconds = _totalSeconds;
  Timer? _timer;
  bool _rewardGranted = false;
  late AnimationController _progressController;

  @override
  void initState() {
    super.initState();
    _progressController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: _totalSeconds),
    )..forward();

    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return;
      if (_remainingSeconds > 1) {
        setState(() {
          _remainingSeconds--;
        });
      } else {
        setState(() {
          _remainingSeconds = 0;
          _rewardGranted = true;
        });
        timer.cancel();
        HapticFeedback.mediumImpact();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _progressController.dispose();
    super.dispose();
  }

  void _claimRewardAndClose() {
    if (!_rewardGranted) return;
    HapticFeedback.mediumImpact();
    Navigator.of(context).pop();
    widget.onRewardEarned();
    if (widget.onDismissed != null) {
      widget.onDismissed!();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _rewardGranted,
      onPopInvokedWithResult: (didPop, result) {
        if (!didPop) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Row(
                children: const [
                  Icon(Icons.lock_clock_rounded, color: Colors.orangeAccent, size: 20),
                  SizedBox(width: 8),
                  Text('Please watch the 5-second test ad to claim reward.'),
                ],
              ),
              duration: const Duration(seconds: 1),
              backgroundColor: AppColors.surface,
            ),
          );
        }
      },
      child: Dialog(
        backgroundColor: Colors.transparent,
        insetPadding: const EdgeInsets.symmetric(horizontal: 18, vertical: 24),
        child: Container(
          width: double.infinity,
          decoration: BoxDecoration(
            color: const Color(0xFF131826),
            borderRadius: BorderRadius.circular(24),
            border: Border.all(color: Colors.amber.withOpacity(0.4), width: 1.5),
            boxShadow: [
              BoxShadow(
                color: Colors.amber.withOpacity(0.15),
                blurRadius: 30,
                spreadRadius: 2,
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Top Bar with Test Ad Banner and Timer/Close Button
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: const BoxDecoration(
                  color: Color(0xFF1E2638),
                  borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    // Test Ad Indicator Badge
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: Colors.amber.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.amber, width: 1),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: const [
                          Icon(Icons.movie_filter_rounded, color: Colors.amber, size: 14),
                          SizedBox(width: 5),
                          Text(
                            'TEST AD',
                            style: TextStyle(
                              color: Colors.amber,
                              fontSize: 11,
                              fontWeight: FontWeight.w900,
                              letterSpacing: 1.2,
                            ),
                          ),
                        ],
                      ),
                    ),

                    // Countdown Timer / Close Button
                    if (!_rewardGranted)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: Colors.black.withOpacity(0.5),
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(color: Colors.white24, width: 0.8),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const SizedBox(
                              width: 12,
                              height: 12,
                              child: CircularProgressIndicator(
                                strokeWidth: 1.8,
                                color: Colors.amber,
                              ),
                            ),
                            const SizedBox(width: 6),
                            Text(
                              'Reward in ${_remainingSeconds}s',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 11.5,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                      )
                    else
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.unlocked,
                          foregroundColor: Colors.black,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          minimumSize: Size.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: _claimRewardAndClose,
                        icon: const Icon(Icons.check_circle_rounded, size: 14, color: Colors.black),
                        label: const Text(
                          'Claim & Close',
                          style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w900),
                        ),
                      ),
                  ],
                ),
              ),

              // Simulated Video Player Area
              Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  children: [
                    // Video Mock Display
                    Container(
                      height: 180,
                      width: double.infinity,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [
                            const Color(0xFF2C1958),
                            AppColors.surface,
                            const Color(0xFF111E38),
                          ],
                        ),
                        borderRadius: BorderRadius.circular(18),
                        border: Border.all(color: Colors.white12),
                      ),
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          // Glowing Center Icon
                          Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Container(
                                padding: const EdgeInsets.all(16),
                                decoration: BoxDecoration(
                                  color: Colors.amber.withOpacity(0.15),
                                  shape: BoxShape.circle,
                                  border: Border.all(color: Colors.amber.withOpacity(0.5), width: 1.5),
                                ),
                                child: Icon(
                                  _rewardGranted ? Icons.verified_rounded : Icons.play_arrow_rounded,
                                  color: _rewardGranted ? AppColors.unlocked : Colors.amber,
                                  size: 38,
                                ),
                              ),
                              const SizedBox(height: 12),
                              Text(
                                _rewardGranted ? '🎉 Reward Earned!' : 'Google AdMob Rewarded Video',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 14,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                _rewardGranted
                                    ? 'Content unlocked successfully!'
                                    : 'Playing simulated test advertisement...',
                                style: const TextStyle(color: Colors.white60, fontSize: 11.5),
                              ),
                            ],
                          ),

                          // Bottom Progress Bar inside Video
                          Positioned(
                            bottom: 0,
                            left: 0,
                            right: 0,
                            child: AnimatedBuilder(
                              animation: _progressController,
                              builder: (context, _) {
                                return ClipRRect(
                                  borderRadius: const BorderRadius.vertical(bottom: Radius.circular(18)),
                                  child: LinearProgressIndicator(
                                    value: _progressController.value,
                                    minHeight: 5,
                                    backgroundColor: Colors.white12,
                                    color: _rewardGranted ? AppColors.unlocked : Colors.amber,
                                  ),
                                );
                              },
                            ),
                          ),
                        ],
                      ),
                    ),

                    const SizedBox(height: 18),

                    // Instructions / Notice
                    Text(
                      _rewardGranted
                          ? 'Tap "Claim Reward" below to access your unlocked content.'
                          : 'Please watch this 5-second test video ad to verify and unlock your request.',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white70, fontSize: 12.5, height: 1.4),
                    ),

                    const SizedBox(height: 16),

                    // Main Action Button
                    SizedBox(
                      width: double.infinity,
                      height: 46,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: _rewardGranted ? AppColors.unlocked : Colors.white10,
                          foregroundColor: _rewardGranted ? Colors.black : Colors.white54,
                          elevation: _rewardGranted ? 4 : 0,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: _rewardGranted ? _claimRewardAndClose : null,
                        child: Text(
                          _rewardGranted ? '✨ Claim Reward & Unlock' : 'Watching Test Ad (${_remainingSeconds}s)...',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
