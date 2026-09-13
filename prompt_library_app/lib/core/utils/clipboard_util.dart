import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../logic/providers/pro_provider.dart';
import '../constants/app_colors.dart';
import '../services/copy_limit_service.dart';

class ClipboardUtil {
  /// Copies prompt text to clipboard with haptic feedback, daily limits, and remaining copy status
  static Future<void> copyToClipboard(
    BuildContext context,
    String text, {
    bool enforceDailyLimit = true,
  }) async {
    if (text.trim().isEmpty) return;

    // Check daily copy limit
    if (enforceDailyLimit) {
      final canCopy = await CopyLimitService.canCopyPrompt(context);
      if (!canCopy) {
        if (context.mounted) {
          CopyLimitService.showCopyLimitDialog(
            context,
            onUnlocked: () => copyToClipboard(context, text, enforceDailyLimit: false),
          );
        }
        return;
      }
    }

    // Tactile haptic feedback
    HapticFeedback.lightImpact();

    await Clipboard.setData(ClipboardData(text: text));

    if (!context.mounted) return;

    if (enforceDailyLimit) {
      await CopyLimitService.registerCopy(context);
    }

    final isPro = context.read<ProProvider>().isProUser;
    final remaining = await CopyLimitService.getRemainingCopies(context);

    String statusSubtitle = '';
    if (isPro) {
      statusSubtitle = '👑 PRO Member • Unlimited Copies';
    } else if (remaining >= 999) {
      statusSubtitle = '⚡ 24h Unlimited Pass Active';
    } else {
      statusSubtitle = '$remaining of 3 free daily copies left';
    }

    ScaffoldMessenger.of(context).hideCurrentSnackBar();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(
                color: AppColors.secondary.withOpacity(0.2),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.check_rounded, color: AppColors.secondary, size: 18),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Prompt copied to clipboard!',
                    style: TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.bold,
                      fontSize: 13.5,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    statusSubtitle,
                    style: TextStyle(
                      color: isPro ? AppColors.accent : AppColors.textSecondary,
                      fontSize: 11.5,
                      fontWeight: isPro ? FontWeight.bold : FontWeight.normal,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
        backgroundColor: AppColors.surface,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(16),
          side: const BorderSide(color: AppColors.border, width: 1),
        ),
        duration: const Duration(seconds: 2),
      ),
    );
  }
}
