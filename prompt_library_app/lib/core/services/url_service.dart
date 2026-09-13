import 'package:url_launcher/url_launcher.dart';
import '../constants/api_endpoints.dart';

class UrlService {
  /// Opens Telegram Bot URL for prompt submissions
  static Future<bool> openTelegramBot() async {
    final uri = Uri.parse(ApiEndpoints.telegramBotUrl);
    if (await canLaunchUrl(uri)) {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      throw 'Could not launch Telegram Bot at $uri';
    }
  }

  /// Opens Telegram Channel URL
  static Future<bool> openTelegramChannel() async {
    final uri = Uri.parse(ApiEndpoints.telegramChannelUrl);
    if (await canLaunchUrl(uri)) {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      throw 'Could not launch Telegram Channel at $uri';
    }
  }
}
