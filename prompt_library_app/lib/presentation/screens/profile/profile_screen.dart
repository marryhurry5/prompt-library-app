import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/services/notification_service.dart';
import '../../../logic/providers/pro_provider.dart';
import '../../../logic/providers/shop_provider.dart';
import '../../../logic/providers/user_provider.dart';
import '../admin/admin_auth_screen.dart';
import '../admin/admin_shop_screen.dart';
import '../auth/user_auth_screen.dart';
import '../pro/pro_upgrade_screen.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});

  void _showPrivacyPolicy(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: const [
            Icon(Icons.privacy_tip_rounded, color: AppColors.primary),
            SizedBox(width: 8),
            Text('Privacy Policy', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ],
        ),
        content: const SingleChildScrollView(
          child: Text(
            'AI Prompt Hub respects your privacy.\n\n'
            '1. Information Collection: We do not require personal registration to browse prompts. Local bookmarks and favorites are stored securely on your device.\n\n'
            '2. AdMob & Analytics: We use Google AdMob to serve non-personalized and rewarded video ads. Standard Google AdMob identifiers may be collected by Google per their privacy policy.\n\n'
            '3. Data Protection: Your data is never sold or shared with third parties.\n\n'
            'For questions, contact support@rtmcreator.com',
            style: TextStyle(color: AppColors.textSecondary, fontSize: 13, height: 1.5),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => launchUrl(
              Uri.parse('https://rtmcreator.com/bots/prompt-bot/privacy.php'),
              mode: LaunchMode.externalApplication,
            ),
            child: const Text('View Online', style: TextStyle(color: AppColors.primaryAccent)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: AppColors.primary)),
          ),
        ],
      ),
    );
  }

  void _showTerms(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: const [
            Icon(Icons.description_rounded, color: AppColors.primary),
            SizedBox(width: 8),
            Text('Terms & Conditions', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ],
        ),
        content: const SingleChildScrollView(
          child: Text(
            'Welcome to AI Prompt Hub.\n\n'
            '1. Usage License: Prompts and digital bundles are provided for personal and commercial creative projects. Reselling raw prompt text files directly is strictly prohibited.\n\n'
            '2. Subscriptions: Pro subscriptions grant an ad-free experience and priority support.\n\n'
            '3. Disclaimer: AI generated output results may vary depending on the target AI model used.',
            style: TextStyle(color: AppColors.textSecondary, fontSize: 13, height: 1.5),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: AppColors.primary)),
          ),
        ],
      ),
    );
  }

  void _showAboutUs(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: const [
            Icon(Icons.info_rounded, color: AppColors.primary),
            SizedBox(width: 8),
            Text('About Us', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
          ],
        ),
        content: const SingleChildScrollView(
          child: Text(
            'AI Prompt Hub is the #1 marketplace and collection for high-converting Midjourney, ChatGPT, Stable Diffusion, and Bing AI prompts.\n\n'
            'Designed to give creators, marketers, and designers instantaneous access to viral AI prompts, reel bundles, and design assets.',
            style: TextStyle(color: AppColors.textSecondary, fontSize: 13, height: 1.5),
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close', style: TextStyle(color: AppColors.primary)),
          ),
        ],
      ),
    );
  }

  Future<void> _contactUs() async {
    final uri = Uri.parse('https://t.me/Prompts_library_bot');
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    final proProvider = context.watch<ProProvider>();
    final shopProvider = context.watch<ShopProvider>();
    final userProvider = context.watch<UserProvider>();

    final isPro = proProvider.isProUser;
    final isAdmin = shopProvider.isAdminLoggedIn;
    final userEmail = userProvider.userEmail ?? 'Guest User';
    final userName = userProvider.userName ?? 'Guest User';

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        title: const Text('Profile & Settings', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            // User Header Info Card
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppColors.border),
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: AppColors.primary.withOpacity(0.2),
                    child: Text(
                      userName.isNotEmpty ? userName[0].toUpperCase() : 'U',
                      style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.bold, fontSize: 22),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          userName,
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          userEmail,
                          style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
                  if (userProvider.isGuest)
                    OutlinedButton(
                      style: OutlinedButton.styleFrom(
                        side: const BorderSide(color: AppColors.primary),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      ),
                      onPressed: () {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => const UserAuthScreen()));
                      },
                      child: const Text('Login', style: TextStyle(color: AppColors.primary, fontSize: 12, fontWeight: FontWeight.bold)),
                    )
                  else
                    IconButton(
                      icon: const Icon(Icons.logout_rounded, color: Colors.redAccent),
                      onPressed: () async {
                        await userProvider.userLogout();
                        if (context.mounted) {
                          Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => const UserAuthScreen()));
                        }
                      },
                    ),
                ],
              ),
            ),

            const SizedBox(height: 16),

            // PRO Upgrade Banner Card
            GestureDetector(
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const ProUpgradeScreen()),
                );
              },
              child: Container(
                width: double.infinity,
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: isPro
                        ? [const Color(0xFF10A37F), const Color(0xFF0078D4)]
                        : [AppColors.primary, AppColors.primaryAccent],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(20),
                  boxShadow: const [
                    BoxShadow(color: Colors.black26, blurRadius: 10, offset: Offset(0, 4)),
                  ],
                ),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.2),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        isPro ? Icons.workspace_premium_rounded : Icons.star_rounded,
                        color: Colors.white,
                        size: 28,
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            isPro ? 'PRO Active Member 👑' : 'Upgrade to PRO 🚀',
                            style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            isPro
                                ? 'Enjoying 100% Ad-Free & Priority Access'
                                : 'Ad-Free Experience & Priority Support (₹29/mo)',
                            style: const TextStyle(color: Colors.white70, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                    const Icon(Icons.arrow_forward_ios_rounded, color: Colors.white, size: 18),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 24),

            // Settings List Container
            Container(
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppColors.border),
              ),
              child: Column(
                children: [
                  _buildListTile(
                    icon: Icons.privacy_tip_rounded,
                    title: 'Privacy Policy',
                    onTap: () => _showPrivacyPolicy(context),
                  ),
                  const Divider(height: 1, color: AppColors.border),
                  _buildListTile(
                    icon: Icons.description_rounded,
                    title: 'Terms & Conditions',
                    onTap: () => _showTerms(context),
                  ),
                  const Divider(height: 1, color: AppColors.border),
                  _buildListTile(
                    icon: Icons.info_outline_rounded,
                    title: 'About Us',
                    onTap: () => _showAboutUs(context),
                  ),
                  const Divider(height: 1, color: AppColors.border),
                  _buildListTile(
                    icon: Icons.headset_mic_rounded,
                    title: 'Contact Support',
                    onTap: _contactUs,
                  ),
                  const Divider(height: 1, color: AppColors.border),
                  _buildListTile(
                    icon: Icons.notifications_active_rounded,
                    title: 'Test Push Notification',
                    iconColor: AppColors.primaryAccent,
                    onTap: () async {
                      await NotificationService.showNewPromptNotification(
                        title: '🎉 Test Push Notification!',
                        body: 'Hey! System push notifications are working 100% perfectly on your device! 🚀',
                      );
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: Text('🔔 Notification sent! Check your phone\'s notification bar.'),
                            backgroundColor: AppColors.primary,
                            duration: Duration(seconds: 3),
                          ),
                        );
                      }
                    },
                  ),
                  const Divider(height: 1, color: AppColors.border),
                  _buildListTile(
                    icon: Icons.admin_panel_settings_rounded,
                    title: isAdmin ? 'Shop Admin Panel' : 'Admin Login Portal',
                    iconColor: AppColors.primaryAccent,
                    onTap: () {
                      if (isAdmin) {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => const AdminShopScreen()));
                      } else {
                        Navigator.push(context, MaterialPageRoute(builder: (_) => const AdminAuthScreen()));
                      }
                    },
                  ),
                  if (isAdmin) ...[
                    const Divider(height: 1, color: AppColors.border),
                    _buildListTile(
                      icon: Icons.logout_rounded,
                      title: 'Logout Admin',
                      iconColor: Colors.redAccent,
                      onTap: () async {
                        await shopProvider.adminLogout();
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Logged out of Admin Session.')),
                          );
                        }
                      },
                    ),
                  ],
                ],
              ),
            ),

            const SizedBox(height: 30),

            // App Version Footer
            Column(
              children: const [
                Text(
                  'AI Prompt Hub',
                  style: TextStyle(color: AppColors.textPrimary, fontWeight: FontWeight.bold, fontSize: 14),
                ),
                SizedBox(height: 4),
                Text(
                  'Version 1.0.1 (Build 2)',
                  style: TextStyle(color: AppColors.textMuted, fontSize: 12),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildListTile({
    required IconData icon,
    required String title,
    Color iconColor = AppColors.primary,
    required VoidCallback onTap,
  }) {
    return ListTile(
      leading: Icon(icon, color: iconColor),
      title: Text(title, style: const TextStyle(color: Colors.white, fontSize: 15, fontWeight: FontWeight.w500)),
      trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.textMuted),
      onTap: onTap,
    );
  }
}
