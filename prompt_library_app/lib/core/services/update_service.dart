import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import 'dart:convert';
import '../constants/api_endpoints.dart';
import '../constants/app_colors.dart';
import '../../data/models/version_model.dart';

class UpdateService {
  /// Checks server for live app update
  static Future<void> checkForUpdate(BuildContext context) async {
    try {
      final PackageInfo packageInfo = await PackageInfo.fromPlatform();
      final String currentVersion = packageInfo.version;

      final response = await http.get(
        Uri.parse('${ApiEndpoints.baseUrl}?action=version_check'),
      );

      if (response.statusCode == 200) {
        String body = response.body.trim();
        if (body.contains('latest_version')) {
          final int versionIndex = body.indexOf('{"success":true,"latest_version"');
          if (versionIndex != -1) {
            body = body.substring(versionIndex);
          }
        }

        final data = json.decode(body);
        if (data['success'] == true) {
          final versionInfo = VersionModel.fromJson(data);
          
          if (_isVersionLower(currentVersion, versionInfo.latestVersion)) {
            if (!context.mounted) return;
            _showUpdateDialog(context, currentVersion, versionInfo);
          }
        }
      }
    } catch (e) {
      debugPrint('[UpdateService] Version check failed: $e');
    }
  }

  /// Compares version numbers (e.g. 1.0.0 < 1.0.1)
  static bool _isVersionLower(String current, String latest) {
    try {
      final cParts = current.split('.').map((e) => int.tryParse(e) ?? 0).toList();
      final lParts = latest.split('.').map((e) => int.tryParse(e) ?? 0).toList();

      for (int i = 0; i < 3; i++) {
        final c = i < cParts.length ? cParts[i] : 0;
        final l = i < lParts.length ? lParts[i] : 0;
        if (c < l) return true;
        if (c > l) return false;
      }
    } catch (e) {
      debugPrint('[UpdateService] Version comparison error: $e');
    }
    return false;
  }

  /// Displays Update Dialog
  static void _showUpdateDialog(
    BuildContext context,
    String currentVersion,
    VersionModel versionInfo,
  ) {
    showDialog(
      context: context,
      barrierDismissible: !versionInfo.forceUpdate,
      builder: (dialogContext) {
        return WillPopScope(
          onWillPop: () async => !versionInfo.forceUpdate,
          child: AlertDialog(
            backgroundColor: AppColors.surface,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
              side: const BorderSide(color: AppColors.primary, width: 1.5),
            ),
            title: Row(
              children: const [
                Icon(Icons.system_update_rounded, color: AppColors.primary, size: 28),
                SizedBox(width: 10),
                Text(
                  'Update Available!',
                  style: TextStyle(
                    color: AppColors.textPrimary,
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'A new version (${versionInfo.latestVersion}) is available. (You currently have v$currentVersion)',
                  style: const TextStyle(color: AppColors.textSecondary, fontSize: 13),
                ),
                const SizedBox(height: 12),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.surfaceLight,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    versionInfo.releaseNotes,
                    style: const TextStyle(color: AppColors.textPrimary, fontSize: 13),
                  ),
                ),
              ],
            ),
            actions: [
              if (!versionInfo.forceUpdate)
                TextButton(
                  onPressed: () => Navigator.pop(dialogContext),
                  child: const Text('Later', style: TextStyle(color: AppColors.textMuted)),
                ),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onPressed: () async {
                  if (versionInfo.updateUrl.isNotEmpty) {
                    final uri = Uri.parse(versionInfo.updateUrl);
                    if (await canLaunchUrl(uri)) {
                      Navigator.pop(dialogContext);
                      await launchUrl(uri, mode: LaunchMode.externalApplication);
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            duration: Duration(seconds: 8),
                            content: Text(
                              '📥 Downloading update APK... Once 100% complete, tap the notification in your top bar to install!',
                            ),
                            backgroundColor: AppColors.surfaceLight,
                          ),
                        );
                      }
                    }
                  }
                },
                icon: const Icon(Icons.download_rounded, color: Colors.white, size: 18),
                label: const Text(
                  'Update Now',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
