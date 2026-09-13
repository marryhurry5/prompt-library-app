import 'dart:convert';
import 'package:http/http.dart' as http;
import '../../data/models/prompt_model.dart';
import '../constants/api_endpoints.dart';

class ApiService {
  final http.Client _client;

  ApiService({http.Client? client}) : _client = client ?? http.Client();

  /// Fetches published prompts from PHP API with optional category, search, creator, and page pagination
  Future<PromptApiResponse> getPrompts({
    int page = 1,
    String? category,
    String? search,
    String? creator,
  }) async {
    try {
      final url = ApiEndpoints.fetchPrompts(
        page: page,
        category: category,
        search: search,
        creator: creator,
      );

      final response = await _client.get(Uri.parse(url)).timeout(
        const Duration(seconds: 15),
      );

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['success'] == true) {
          return PromptApiResponse.fromJson(data);
        } else {
          throw Exception(data['error'] ?? 'Failed to load prompts from API');
        }
      } else {
        throw Exception('Server error: HTTP ${response.statusCode}');
      }
    } catch (e) {
      throw Exception('Network error: $e');
    }
  }

  /// Fetches dynamic categories list from PHP API
  Future<List<String>> getCategories() async {
    try {
      final url = '${ApiEndpoints.baseUrl}?action=categories';
      final response = await _client.get(Uri.parse(url)).timeout(
        const Duration(seconds: 10),
      );

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['success'] == true && data['categories'] != null) {
          final List<dynamic> list = data['categories'];
          final names = list
              .where((c) => c['is_active'] == null || c['is_active'] == 1 || c['is_active'] == '1')
              .map((c) => c['name'].toString())
              .toList();
          return names;
        }
      }
    } catch (e) {
      // Return empty list to trigger fallback
    }
    return [];
  }

  /// Likes/unlikes a prompt and returns the updated likes count from MySQL
  Future<int?> likePrompt(int promptId, {bool isLike = true}) async {
    try {
      final url = '${ApiEndpoints.baseUrl}?action=like_prompt';
      final response = await _client.post(
        Uri.parse(url),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({
          'prompt_id': promptId,
          'is_like': isLike,
        }),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['success'] == true && data['likes'] != null) {
          return data['likes'] is int ? data['likes'] : int.tryParse(data['likes'].toString());
        }
      }
    } catch (_) {}
    return null;
  }

  /// Increments copy count in MySQL database when user copies a prompt
  Future<int?> copyPrompt(int promptId) async {
    try {
      final url = '${ApiEndpoints.baseUrl}?action=copy_prompt';
      final response = await _client.post(
        Uri.parse(url),
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'prompt_id': promptId}),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['success'] == true && data['copies'] != null) {
          return data['copies'] is int ? data['copies'] : int.tryParse(data['copies'].toString());
        }
      }
    } catch (_) {}
    return null;
  }

  /// Fetches creator profile summary stats from PHP API
  Future<Map<String, dynamic>?> getCreatorInfo(String username) async {
    try {
      final cleanUser = Uri.encodeComponent(username);
      final url = '${ApiEndpoints.baseUrl}?action=creator_info&creator=$cleanUser';
      final response = await _client.get(Uri.parse(url)).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final Map<String, dynamic> data = jsonDecode(response.body);
        if (data['success'] == true) {
          return data;
        }
      }
    } catch (_) {}
    return null;
  }
}

class PromptApiResponse {
  final bool success;
  final int total;
  final int page;
  final int perPage;
  final int totalPages;
  final List<PromptModel> prompts;

  PromptApiResponse({
    required this.success,
    required this.total,
    required this.page,
    required this.perPage,
    required this.totalPages,
    required this.prompts,
  });

  factory PromptApiResponse.fromJson(Map<String, dynamic> json) {
    final rawPrompts = json['prompts'] as List<dynamic>? ?? [];
    final promptsList = rawPrompts
        .map((p) => PromptModel.fromJson(p as Map<String, dynamic>))
        .toList();

    return PromptApiResponse(
      success: json['success'] ?? false,
      total: json['total'] ?? 0,
      page: json['page'] ?? 1,
      perPage: json['per_page'] ?? 12,
      totalPages: json['total_pages'] ?? 1,
      prompts: promptsList,
    );
  }
}
