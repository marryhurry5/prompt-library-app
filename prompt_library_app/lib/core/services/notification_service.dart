import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../data/models/prompt_model.dart';

class NotificationService {
  static final FlutterLocalNotificationsPlugin _notificationsPlugin =
      FlutterLocalNotificationsPlugin();

  static bool _isInitialized = false;

  /// Initializes system push notifications
  static Future<void> init() async {
    if (_isInitialized) return;

    const AndroidInitializationSettings initializationSettingsAndroid =
        AndroidInitializationSettings('@mipmap/ic_launcher');

    const InitializationSettings initializationSettings = InitializationSettings(
      android: initializationSettingsAndroid,
    );

    await _notificationsPlugin.initialize(
      initializationSettings,
      onDidReceiveNotificationResponse: (NotificationResponse details) {
        debugPrint('[NotificationService] Notification tapped: ${details.payload}');
      },
    );

    _isInitialized = true;
  }

  /// Checks if new prompts exist and triggers a push notification
  static Future<void> checkForNewPrompts(List<PromptModel> prompts) async {
    if (prompts.isEmpty) return;

    try {
      await init();
      final prefs = await SharedPreferences.getInstance();
      final int lastNotifiedId = prefs.getInt('last_notified_prompt_id') ?? 0;

      final latestPrompt = prompts.first;
      final int latestId = int.tryParse(latestPrompt.id.toString()) ?? 0;

      if (latestId > 0 && latestId > lastNotifiedId) {
        // Save new latest ID
        await prefs.setInt('last_notified_prompt_id', latestId);

        // Don't notify on first launch when lastNotifiedId is 0
        if (lastNotifiedId > 0) {
          await showNewPromptNotification(
            title: '🎉 New Prompt Uploaded!',
            body: 'Hey! A new prompt has just been uploaded. Do you wanna check? 🚀',
          );
        }
      }
    } catch (e) {
      debugPrint('[NotificationService] Check new prompts error: $e');
    }
  }

  /// Displays local system push notification
  static Future<void> showNewPromptNotification({
    required String title,
    required String body,
  }) async {
    await init();

    const AndroidNotificationDetails androidDetails = AndroidNotificationDetails(
      'new_prompts_channel',
      'New Prompts Alerts',
      channelDescription: 'Notifications for new AI prompt uploads',
      importance: Importance.max,
      priority: Priority.high,
      showWhen: true,
      color: Color(0xFF6366F1),
    );

    const NotificationDetails platformDetails = NotificationDetails(
      android: androidDetails,
    );

    await _notificationsPlugin.show(
      DateTime.now().millisecondsSinceEpoch ~/ 1000,
      title,
      body,
      platformDetails,
    );
  }

  /// Schedules daily evening push notification digest (7:00 PM IST)
  static Future<void> scheduleDailyEveningDigest() async {
    try {
      await init();
      final prefs = await SharedPreferences.getInstance();
      final String todayKey = 'daily_digest_${DateTime.now().year}_${DateTime.now().month}_${DateTime.now().day}';
      
      final bool alreadySent = prefs.getBool(todayKey) ?? false;
      final int currentHour = DateTime.now().hour;

      if (!alreadySent && currentHour >= 18) {
        await prefs.setBool(todayKey, true);
        await showNewPromptNotification(
          title: '🔥 Top Prompt of the Day is Live!',
          body: 'Check out the new trending AI art & prompt styles now 🚀',
        );
      }
    } catch (e) {
      debugPrint('[NotificationService] Daily digest error: $e');
    }
  }
}
