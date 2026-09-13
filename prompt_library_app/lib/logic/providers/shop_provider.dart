import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/constants/api_endpoints.dart';
import '../../data/models/shop_item_model.dart';

class ShopProvider extends ChangeNotifier {
  List<ShopItemModel> _items = [];
  bool _isLoading = false;
  String? _errorMessage;
  final Set<int> _unlockedItemIds = {};
  String? _loggedInAdminEmail;

  List<ShopItemModel> get items => _items;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  String? get loggedInAdminEmail => _loggedInAdminEmail;
  bool get isAdminLoggedIn => _loggedInAdminEmail != null && _loggedInAdminEmail!.isNotEmpty;

  ShopProvider() {
    fetchShopItems();
    _loadAdminSession();
  }

  Future<void> _loadAdminSession() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _loggedInAdminEmail = prefs.getString('shop_admin_email');
      notifyListeners();
    } catch (e) {
      debugPrint('[ShopProvider] Load session error: $e');
    }
  }

  /// Checks if a product has been unlocked in the current session
  bool isItemUnlocked(int id) {
    return _unlockedItemIds.contains(id);
  }

  /// Unlocks product after AdMob Rewarded ad completion
  void unlockItem(int id) {
    _unlockedItemIds.add(id);
    notifyListeners();
  }

  /// Fetches published shop products from REST API
  Future<void> fetchShopItems() async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    try {
      final response = await http.get(
        Uri.parse('${ApiEndpoints.baseUrl}?action=shop_items'),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true && data['items'] != null) {
          final List rawList = data['items'];
          _items = rawList.map((e) => ShopItemModel.fromJson(e)).toList();
        } else {
          _errorMessage = data['error'] ?? 'Failed to load shop items.';
        }
      } else {
        _errorMessage = 'Server error (${response.statusCode})';
      }
    } catch (e) {
      _errorMessage = 'Connection error. Please check your internet.';
      debugPrint('[ShopProvider] Error: $e');
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Admin Registration with Encrypted Passwords
  Future<Map<String, dynamic>> adminRegister(String email, String password) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=admin_register'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'email': email, 'password': password}),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          _loggedInAdminEmail = email;
          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('shop_admin_email', email);
          notifyListeners();
          return {'success': true};
        }
        return {'success': false, 'error': data['error'] ?? 'Registration failed.'};
      }
    } catch (e) {
      debugPrint('[ShopProvider] Admin Register error: $e');
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  /// Admin Login with Password Verification
  Future<Map<String, dynamic>> adminLogin(String email, String password) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=admin_login'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'email': email, 'password': password}),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          _loggedInAdminEmail = email;
          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('shop_admin_email', email);
          notifyListeners();
          return {'success': true};
        }
        return {'success': false, 'error': data['error'] ?? 'Invalid email or password.'};
      }
    } catch (e) {
      debugPrint('[ShopProvider] Admin Login error: $e');
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  /// Admin Logout
  Future<void> adminLogout() async {
    _loggedInAdminEmail = null;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('shop_admin_email');
    notifyListeners();
  }

  /// Adds a new product item (In-App Admin)
  Future<bool> addShopItem({
    required String title,
    required String description,
    required String imageUrl,
    required String accessLink,
    required String itemType,
  }) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=add_shop_item'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'title': title,
          'description': description,
          'image_url': imageUrl,
          'access_link': accessLink,
          'item_type': itemType,
        }),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          await fetchShopItems(); // Refresh items
          return true;
        }
      }
    } catch (e) {
      debugPrint('[ShopProvider] Failed to add item: $e');
    }
    return false;
  }

  /// Edits an existing product item (In-App Admin)
  Future<bool> editShopItem({
    required int id,
    required String title,
    required String description,
    required String imageUrl,
    required String accessLink,
    required String itemType,
  }) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=edit_shop_item'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'id': id,
          'title': title,
          'description': description,
          'image_url': imageUrl,
          'access_link': accessLink,
          'item_type': itemType,
        }),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          await fetchShopItems(); // Refresh items
          return true;
        }
      }
    } catch (e) {
      debugPrint('[ShopProvider] Failed to edit item: $e');
    }
    return false;
  }

  /// Deletes/deactivates a product item (In-App Admin)
  Future<bool> deleteShopItem(int id) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=delete_shop_item'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'id': id}),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          _items.removeWhere((item) => item.id == id);
          notifyListeners();
          return true;
        }
      }
    } catch (e) {
      debugPrint('[ShopProvider] Failed to delete item: $e');
    }
    return false;
  }
}
