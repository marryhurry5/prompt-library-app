import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../data/models/prompt_model.dart';

class FavoritesProvider extends ChangeNotifier {
  static const String _favKey = 'user_favorite_prompt_ids';
  final Set<String> _favoriteIds = {};
  List<PromptModel> _favoritePrompts = [];

  Set<String> get favoriteIds => _favoriteIds;
  List<PromptModel> get favoritePrompts => _favoritePrompts;

  FavoritesProvider() {
    _loadFavorites();
  }

  /// Loads saved favorite prompt IDs from local storage
  Future<void> _loadFavorites() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final List<String>? saved = prefs.getStringList(_favKey);
      if (saved != null) {
        _favoriteIds.addAll(saved);
        notifyListeners();
      }
    } catch (e) {
      debugPrint('[FavoritesProvider] Failed to load favorites: $e');
    }
  }

  /// Checks if a prompt is favorited
  bool isFavorite(String promptId) {
    return _favoriteIds.contains(promptId);
  }

  /// Toggles favorite status for a prompt
  Future<void> toggleFavorite(PromptModel prompt) async {
    final String id = prompt.id.toString();
    if (_favoriteIds.contains(id)) {
      _favoriteIds.remove(id);
      _favoritePrompts.removeWhere((p) => p.id.toString() == id);
    } else {
      _favoriteIds.add(id);
      if (!_favoritePrompts.any((p) => p.id.toString() == id)) {
        _favoritePrompts.add(prompt);
      }
    }

    notifyListeners();

    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setStringList(_favKey, _favoriteIds.toList());
    } catch (e) {
      debugPrint('[FavoritesProvider] Failed to save favorites: $e');
    }
  }

  /// Synchronizes favorite prompt objects when feeds update
  void syncFavorites(List<PromptModel> allPrompts) {
    bool changed = false;
    for (final prompt in allPrompts) {
      final String id = prompt.id.toString();
      if (_favoriteIds.contains(id)) {
        final int index = _favoritePrompts.indexWhere((p) => p.id.toString() == id);
        if (index == -1) {
          _favoritePrompts.add(prompt);
          changed = true;
        }
      }
    }
    if (changed) notifyListeners();
  }
}
