import 'package:flutter/foundation.dart';
import '../../core/services/api_service.dart';
import '../../data/models/prompt_model.dart';

class SearchProvider extends ChangeNotifier {
  final ApiService _apiService;

  SearchProvider({ApiService? apiService})
      : _apiService = apiService ?? ApiService();

  List<PromptModel> _searchResults = [];
  bool _isSearching = false;
  String? _searchError;
  String _currentQuery = '';

  List<PromptModel> get searchResults => _searchResults;
  bool get isSearching => _isSearching;
  String? get searchError => _searchError;
  String get currentQuery => _currentQuery;

  /// Performs real-time search query against PHP API
  Future<void> search(String query) async {
    final trimmed = query.trim();
    _currentQuery = trimmed;

    if (trimmed.isEmpty) {
      _searchResults = [];
      _isSearching = false;
      _searchError = null;
      notifyListeners();
      return;
    }

    _isSearching = true;
    _searchError = null;
    notifyListeners();

    try {
      final response = await _apiService.getPrompts(search: trimmed);
      _searchResults = response.prompts;
      _isSearching = false;
      notifyListeners();
    } catch (e) {
      _searchError = e.toString().replaceAll('Exception: ', '');
      _isSearching = false;
      notifyListeners();
    }
  }

  void clearSearch() {
    _searchResults = [];
    _currentQuery = '';
    _isSearching = false;
    _searchError = null;
    notifyListeners();
  }
}
