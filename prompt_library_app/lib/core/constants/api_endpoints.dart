class ApiEndpoints {
  // Base URL pointing to existing PHP backend REST API
  static const String baseUrl = 'https://rtmcreator.com/bots/prompt-bot/api.php';
  
  // Telegram Bot Submission URL
  static const String telegramBotUrl = 'https://t.me/Prompts_library_bot';
  static const String telegramChannelUrl = 'https://t.me/ai_prompt_store';
  static const String privacyPolicyUrl = 'https://rtmcreator.com/bots/prompt-bot/privacy.php';

  // Endpoint helpers
  static String fetchPrompts({int page = 1, String? category, String? search, String? creator}) {
    final queryParams = <String>[];
    queryParams.add('page=$page');
    if (category != null && category.isNotEmpty && category != 'All') {
      queryParams.add('category=${Uri.encodeComponent(category)}');
    }
    if (search != null && search.trim().isNotEmpty) {
      queryParams.add('search=${Uri.encodeComponent(search.trim())}');
    }
    if (creator != null && creator.trim().isNotEmpty) {
      queryParams.add('creator=${Uri.encodeComponent(creator.trim())}');
    }
    return '$baseUrl?${queryParams.join('&')}';
  }
}
