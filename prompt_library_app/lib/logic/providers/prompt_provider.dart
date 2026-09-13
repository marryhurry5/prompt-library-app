import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/services/api_service.dart';
import '../../core/services/notification_service.dart';
import '../../data/models/category_model.dart';
import '../../data/models/prompt_model.dart';

class PromptProvider extends ChangeNotifier {
  final ApiService _apiService;

  PromptProvider({ApiService? apiService})
      : _apiService = apiService ?? ApiService() {
    _loadCachedPrompts();
    fetchCategories();
    _loadLikedPrompts();
  }

  List<PromptModel> _prompts = [];
  List<CategoryModel> _categories = CategoryModel.defaultCategories;
  bool _isLoading = false;
  bool _isMoreLoading = false;
  String? _errorMessage;
  int _currentPage = 1;
  int _totalPages = 1;
  String _selectedCategory = 'All';

  // Set of unlocked prompt IDs for the current user session
  final Set<int> _unlockedPromptIds = {};

  // Set of persistent liked prompt IDs for 1-person-1-like enforcement
  Set<int> _likedPromptIds = {};

  List<PromptModel> get prompts => _prompts;
  List<CategoryModel> get categories => _categories;
  bool get isLoading => _isLoading;
  bool get isMoreLoading => _isMoreLoading;
  String? get errorMessage => _errorMessage;
  int get currentPage => _currentPage;
  int get totalPages => _totalPages;
  bool get hasMore => _currentPage < _totalPages;
  String get selectedCategory => _selectedCategory;

  List<PromptModel> get trendingPrompts =>
      _prompts.where((p) => p.isChallenge).toList();

  static const String _cacheKey = 'cached_feed_prompts';

  Future<void> _loadCachedPrompts() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final cachedJson = prefs.getString(_cacheKey);
      if (cachedJson != null && cachedJson.isNotEmpty && _prompts.isEmpty) {
        final List<dynamic> decoded = jsonDecode(cachedJson);
        final list = decoded.map((e) => PromptModel.fromJson(e as Map<String, dynamic>)).toList();
        if (list.isNotEmpty && _prompts.isEmpty) {
          _prompts = list;
          notifyListeners();
        }
      }
    } catch (e) {
      debugPrint('[PromptProvider] Error loading cached prompts: $e');
    }
  }

  Future<void> _savePromptsToCache() async {
    try {
      if (_selectedCategory == 'All' && _prompts.isNotEmpty) {
        final prefs = await SharedPreferences.getInstance();
        final toCache = _prompts.take(50).map((p) => p.toJson()).toList();
        await prefs.setString(_cacheKey, jsonEncode(toCache));
      }
    } catch (e) {
      debugPrint('[PromptProvider] Error saving cached prompts: $e');
    }
  }

  Future<void> _loadLikedPrompts() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final list = prefs.getStringList('user_liked_prompt_ids') ?? [];
      _likedPromptIds = list.map((e) => int.tryParse(e) ?? 0).where((id) => id > 0).toSet();
      notifyListeners();
    } catch (_) {}
  }

  Future<void> _saveLikedPrompts() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setStringList('user_liked_prompt_ids', _likedPromptIds.map((e) => e.toString()).toList());
    } catch (_) {}
  }

  bool isLiked(int promptId) {
    return _likedPromptIds.contains(promptId);
  }

  /// Toggles like/unlike for a prompt with 1-person-1-like enforcement & MySQL sync
  Future<void> toggleLike(int promptId) async {
    final bool currentlyLiked = _likedPromptIds.contains(promptId);
    final bool newLikeState = !currentlyLiked;

    if (newLikeState) {
      _likedPromptIds.add(promptId);
    } else {
      _likedPromptIds.remove(promptId);
    }
    _saveLikedPrompts();

    final index = _prompts.indexWhere((p) => p.id == promptId);
    if (index != -1) {
      final currentLikes = _prompts[index].likesCount;
      final newLikes = newLikeState ? currentLikes + 1 : (currentLikes > 0 ? currentLikes - 1 : 0);
      _prompts[index] = _prompts[index].copyWith(likesCount: newLikes);
      notifyListeners();

      final updatedCount = await _apiService.likePrompt(promptId, isLike: newLikeState);
      if (updatedCount != null) {
        _prompts[index] = _prompts[index].copyWith(likesCount: updatedCount);
        notifyListeners();
      }
    } else {
      notifyListeners();
      _apiService.likePrompt(promptId, isLike: newLikeState);
    }
  }

  /// Fetches dynamic categories from backend API
  Future<void> fetchCategories() async {
    try {
      final names = await _apiService.getCategories();
      if (names.isNotEmpty) {
        final list = <CategoryModel>[
          const CategoryModel(name: 'All', icon: Icons.grid_view_rounded, color: Color(0xFF6C5CE7)),
        ];
        for (final name in names) {
          if (name.toLowerCase() != 'all') {
            list.add(CategoryModel.fromName(name));
          }
        }
        _categories = list;
        notifyListeners();
      }
    } catch (_) {}
  }

  /// Fetches published prompts from API
  Future<void> fetchPrompts({bool refresh = false, String? category}) async {
    if (refresh) {
      _currentPage = 1;
      if (_selectedCategory != 'All' || _prompts.isEmpty) {
        _prompts = [];
      }
      fetchCategories();
    }

    if (category != null) {
      _selectedCategory = category;
      _currentPage = 1;
      _prompts = [];
    }

    if (_currentPage == 1) {
      _isLoading = true;
      _errorMessage = null;
      notifyListeners();
    } else {
      _isMoreLoading = true;
      notifyListeners();
    }

    try {
      final response = await _apiService.getPrompts(
        page: _currentPage,
        category: _selectedCategory,
      );

      final newPrompts = response.prompts.map((p) {
        // Retain unlock state if already unlocked in this session
        if (_unlockedPromptIds.contains(p.id)) {
          return p.copyWith(isLocked: false);
        }
        return p;
      }).toList();

      if (_currentPage == 1) {
        _prompts = newPrompts;
        NotificationService.checkForNewPrompts(_prompts);
        _savePromptsToCache();
      } else {
        _prompts.addAll(newPrompts);
      }

      _totalPages = response.totalPages;
      _isLoading = false;
      _isMoreLoading = false;
      notifyListeners();
    } catch (e) {
      _errorMessage = e.toString().replaceAll('Exception: ', '');
      _isLoading = false;
      _isMoreLoading = false;
      notifyListeners();
    }
  }

  /// Loads next page of prompts
  Future<void> loadMore() async {
    if (_isMoreLoading || !hasMore || _isLoading) return;
    _currentPage++;
    await fetchPrompts();
  }

  /// Unlocks a locked prompt upon AdMob Rewarded Ad completion
  void unlockPrompt(int promptId) {
    _unlockedPromptIds.add(promptId);
    final index = _prompts.indexWhere((p) => p.id == promptId);
    if (index != -1) {
      _prompts[index] = _prompts[index].copyWith(isLocked: false);
      notifyListeners();
    }
  }

  bool isPromptUnlocked(int promptId) {
    return _unlockedPromptIds.contains(promptId);
  }

  /// Increments copy count for a prompt and syncs with MySQL
  Future<void> incrementCopyCount(int promptId) async {
    final index = _prompts.indexWhere((p) => p.id == promptId);
    if (index != -1) {
      final currentCopies = _prompts[index].copiesCount;
      _prompts[index] = _prompts[index].copyWith(copiesCount: currentCopies + 1);
      notifyListeners();

      final newCount = await _apiService.copyPrompt(promptId);
      if (newCount != null) {
        _prompts[index] = _prompts[index].copyWith(copiesCount: newCount);
        notifyListeners();
      }
    } else {
      _apiService.copyPrompt(promptId);
    }
  }
}
