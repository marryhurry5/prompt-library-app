import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/constants/api_endpoints.dart';

class UserProvider extends ChangeNotifier {
  String? _userEmail;
  String? _userName;
  bool _isGuest = false;
  bool _isLoading = false;

  String? get userEmail => _userEmail;
  String? get userName => _userName;
  bool get isGuest => _isGuest;
  bool get isLoggedIn => (_userEmail != null && _userEmail!.isNotEmpty) || _isGuest;
  bool get isLoading => _isLoading;

  UserProvider() {
    _loadUserSession();
  }

  Future<void> _loadUserSession() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _userEmail = prefs.getString('app_user_email');
      _userName = prefs.getString('app_user_name');
      _isGuest = prefs.getBool('app_is_guest') ?? false;
      notifyListeners();
    } catch (e) {
      debugPrint('[UserProvider] Load session error: $e');
    }
  }

  Future<void> continueAsGuest() async {
    _isGuest = true;
    _userEmail = 'guest@aiprompthub.com';
    _userName = 'Guest User';
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool('app_is_guest', true);
    await prefs.setString('app_user_email', _userEmail!);
    await prefs.setString('app_user_name', _userName!);
    notifyListeners();
  }

  Future<Map<String, dynamic>> userRegister({
    required String name,
    required String email,
    required String password,
  }) async {
    _isLoading = true;
    notifyListeners();

    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=user_register'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'name': name, 'email': email, 'password': password}),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true && data['user'] != null) {
          _userEmail = email;
          _userName = name;
          _isGuest = false;
          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('app_user_email', email);
          await prefs.setString('app_user_name', name);
          await prefs.setBool('app_is_guest', false);
          return {'success': true};
        }
        return {'success': false, 'error': data['error'] ?? 'Registration failed.'};
      }
    } catch (e) {
      debugPrint('[UserProvider] Register error: $e');
    } finally {
      _isLoading = false;
      notifyListeners();
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  Future<Map<String, dynamic>> userLogin({
    required String email,
    required String password,
  }) async {
    _isLoading = true;
    notifyListeners();

    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=user_login'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'email': email, 'password': password}),
      );

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true && data['user'] != null) {
          _userEmail = data['user']['email'];
          _userName = data['user']['name'] ?? 'User';
          _isGuest = false;
          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('app_user_email', _userEmail!);
          await prefs.setString('app_user_name', _userName!);
          await prefs.setBool('app_is_guest', false);
          return {'success': true, 'user': data['user']};
        }
        return {'success': false, 'error': data['error'] ?? 'Invalid email or password.'};
      }
    } catch (e) {
      debugPrint('[UserProvider] Login error: $e');
    } finally {
      _isLoading = false;
      notifyListeners();
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  Future<Map<String, dynamic>> forgotPassword(String email) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=forgot_password'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({'email': email}),
      );

      if (response.statusCode == 200) {
        return json.decode(response.body);
      }
    } catch (e) {
      debugPrint('[UserProvider] Forgot password error: $e');
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  Future<Map<String, dynamic>> resetPassword({
    required String email,
    required String otp,
    required String newPassword,
  }) async {
    try {
      final response = await http.post(
        Uri.parse('${ApiEndpoints.baseUrl}?action=reset_password'),
        headers: {'Content-Type': 'application/json'},
        body: json.encode({
          'email': email,
          'otp': otp,
          'new_password': newPassword,
        }),
      );

      if (response.statusCode == 200) {
        return json.decode(response.body);
      }
    } catch (e) {
      debugPrint('[UserProvider] Reset password error: $e');
    }
    return {'success': false, 'error': 'Network connection error.'};
  }

  Future<void> userLogout() async {
    _userEmail = null;
    _userName = null;
    _isGuest = false;
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('app_user_email');
    await prefs.remove('app_user_name');
    await prefs.remove('app_is_guest');
    notifyListeners();
  }
}
