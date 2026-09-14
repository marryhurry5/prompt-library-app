import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/services/admob_service.dart';
import '../../../core/utils/clipboard_util.dart';
import '../../../data/models/prompt_model.dart';
import '../../../logic/providers/favorites_provider.dart';
import '../../../logic/providers/prompt_provider.dart';
import '../profile/creator_profile_screen.dart';

class PromptDetailsScreen extends StatefulWidget {
  final PromptModel prompt;

  const PromptDetailsScreen({super.key, required this.prompt});

  @override
  State<PromptDetailsScreen> createState() => _PromptDetailsScreenState();
}

class _PromptDetailsScreenState extends State<PromptDetailsScreen> {
  late AdMobService _adMobService;
  bool _isAdLoading = false;

  @override
  void initState() {
    super.initState();
    _adMobService = AdMobService();
    if (widget.prompt.isLocked) {
      _adMobService.loadRewardedAd();
    }
  }

  @override
  void dispose() {
    _adMobService.dispose();
    super.dispose();
  }

  void _watchAdToUnlock() {
    setState(() => _isAdLoading = true);

    _adMobService.showRewardedAd(
      context: context,
      onRewardEarned: () {
        if (mounted) {
          setState(() => _isAdLoading = false);
          context.read<PromptProvider>().unlockPrompt(widget.prompt.id);
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Row(
                children: const [
                  Icon(Icons.lock_open_rounded, color: AppColors.unlocked, size: 20),
                  SizedBox(width: 10),
                  Text('🎉 Prompt unlocked successfully!'),
                ],
              ),
              backgroundColor: AppColors.surfaceLight,
            ),
          );
        }
      },
      onAdDismissed: () {
        if (mounted) setState(() => _isAdLoading = false);
      },
    );
  }

  Future<void> _launchExternalUrl(String url) async {
    try {
      final uri = Uri.parse(url);
      if (await canLaunchUrl(uri)) {
        await launchUrl(uri, mode: LaunchMode.externalApplication);
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Could not open: $url')),
          );
        }
      }
    } catch (e) {
      debugPrint('[PromptDetails] Error launching URL: $e');
    }
  }

  void _sharePrompt() {
    final String shareText =
        '🔥 Check out this AI Prompt on AI Prompt Hub!\n\n'
        '📌 Category: ${widget.prompt.category}\n'
        '📝 Title: ${widget.prompt.displayTitle}\n\n'
        '${widget.prompt.prompt}\n\n'
        '📲 Shared from AI Prompt Hub App';
    
    Share.share(shareText, subject: widget.prompt.displayTitle);
  }

  void _showFullscreenImage(BuildContext context, String imageUrl) {
    HapticFeedback.lightImpact();
    Navigator.push(
      context,
      PageRouteBuilder(
        opaque: false,
        barrierDismissible: true,
        pageBuilder: (context, _, __) {
          return Scaffold(
            backgroundColor: Colors.black.withOpacity(0.92),
            body: Stack(
              children: [
                Center(
                  child: InteractiveViewer(
                    clipBehavior: Clip.none,
                    minScale: 0.8,
                    maxScale: 4.0,
                    child: CachedNetworkImage(
                      imageUrl: imageUrl,
                      fit: BoxFit.contain,
                      placeholder: (context, url) => const Center(
                        child: CircularProgressIndicator(color: AppColors.primary),
                      ),
                      errorWidget: (context, url, error) => const Icon(
                        Icons.broken_image_rounded,
                        color: Colors.white54,
                        size: 60,
                      ),
                    ),
                  ),
                ),
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Container(
                          decoration: const BoxDecoration(
                            color: Colors.black54,
                            shape: BoxShape.circle,
                          ),
                          child: IconButton(
                            icon: const Icon(Icons.close_rounded, color: Colors.white, size: 24),
                            onPressed: () => Navigator.pop(context),
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                          decoration: BoxDecoration(
                            color: Colors.black54,
                            borderRadius: BorderRadius.circular(16),
                          ),
                          child: const Text(
                            'Pinch to Zoom',
                            style: TextStyle(color: Colors.white70, fontSize: 12),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final promptProvider = context.watch<PromptProvider>();
    final favProvider = context.watch<FavoritesProvider>();
    final isUnlocked = !widget.prompt.isLocked || promptProvider.isPromptUnlocked(widget.prompt.id);
    final isFav = favProvider.isFavorite(widget.prompt.id.toString());

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          widget.prompt.category,
          style: const TextStyle(color: AppColors.textPrimary, fontSize: 16, fontWeight: FontWeight.bold),
        ),
        actions: [
          IconButton(
            icon: Icon(
              isFav ? Icons.favorite_rounded : Icons.favorite_border_rounded,
              color: isFav ? Colors.redAccent : Colors.white,
            ),
            onPressed: () {
              HapticFeedback.lightImpact();
              favProvider.toggleFavorite(widget.prompt);
              ScaffoldMessenger.of(context).hideCurrentSnackBar();
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  duration: const Duration(seconds: 1),
                  content: Text(isFav ? 'Removed from Favorites' : 'Saved to Favorites ❤️'),
                ),
              );
            },
          ),
          IconButton(
            icon: const Icon(Icons.share_rounded, color: Colors.white),
            onPressed: _sharePrompt,
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // 1. Preview Media Image (16:9 for Thumbnail category)
            if (widget.prompt.previewImageUrl.isNotEmpty)
              Hero(
                tag: 'prompt_img_${widget.prompt.id}',
                child: GestureDetector(
                  onTap: () => _showFullscreenImage(context, widget.prompt.previewImageUrl),
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(20),
                    child: Stack(
                      alignment: Alignment.bottomRight,
                      children: [
                        AspectRatio(
                          aspectRatio: widget.prompt.category.toLowerCase().contains('thumb') ? 16 / 9 : 1.2,
                          child: CachedNetworkImage(
                            imageUrl: widget.prompt.previewImageUrl,
                            fit: BoxFit.cover,
                            placeholder: (context, url) => Container(
                              color: AppColors.surface,
                              child: const Center(child: CircularProgressIndicator(color: AppColors.primary)),
                            ),
                            errorWidget: (context, url, error) => Container(
                              color: AppColors.surface,
                              child: const Icon(Icons.broken_image_rounded, color: AppColors.textMuted, size: 50),
                            ),
                          ),
                        ),
                        // Zoom hint badge
                        Container(
                          margin: const EdgeInsets.all(12),
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.65),
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: Colors.white24, width: 0.8),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: const [
                              Icon(Icons.zoom_in_rounded, color: Colors.white, size: 14),
                              SizedBox(width: 4),
                              Text(
                                'Tap to Zoom',
                                style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),

            const SizedBox(height: 20),

            // 2. Title, Author Info & Like Button
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                GestureDetector(
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) => CreatorProfileScreen(username: widget.prompt.username),
                      ),
                    );
                  },
                  child: Row(
                    children: [
                      if (widget.prompt.isChallenge) ...[
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                          decoration: BoxDecoration(
                            color: AppColors.trending,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            children: const [
                              Icon(Icons.local_fire_department_rounded, color: Colors.white, size: 14),
                              SizedBox(width: 4),
                              Text(
                                'Trending',
                                style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 10),
                      ],
                      const Icon(Icons.account_circle_rounded, color: AppColors.primaryAccent, size: 18),
                      const SizedBox(width: 5),
                      Text(
                        widget.prompt.username,
                        style: const TextStyle(
                          color: AppColors.primaryAccent,
                          fontSize: 14,
                          fontWeight: FontWeight.bold,
                          decoration: TextDecoration.underline,
                        ),
                      ),
                    ],
                  ),
                ),

                // Interactive Highlighted Social Media Like Button
                Consumer<PromptProvider>(
                  builder: (context, provider, _) {
                    final bool isLiked = provider.isLiked(widget.prompt.id);
                    return GestureDetector(
                      onTap: () {
                        HapticFeedback.lightImpact();
                        provider.toggleLike(widget.prompt.id);
                      },
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
                        decoration: BoxDecoration(
                          color: isLiked ? Colors.pinkAccent.withOpacity(0.25) : AppColors.surfaceLight.withOpacity(0.3),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: isLiked ? Colors.pinkAccent : AppColors.border,
                            width: 1.5,
                          ),
                          boxShadow: isLiked ? [
                            BoxShadow(
                              color: Colors.pinkAccent.withOpacity(0.3),
                              blurRadius: 8,
                              offset: const Offset(0, 2),
                            )
                          ] : [],
                        ),
                        child: Row(
                          children: [
                            Icon(
                              isLiked ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                              color: isLiked ? Colors.pinkAccent : AppColors.textMuted,
                              size: 16,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              '${widget.prompt.likesCount} ${widget.prompt.likesCount == 1 ? 'Like' : 'Likes'}',
                              style: TextStyle(
                                color: isLiked ? Colors.pinkAccent : AppColors.textSecondary,
                                fontSize: 13,
                                fontWeight: isLiked ? FontWeight.bold : FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ],
            ),

            const SizedBox(height: 10),

            Text(
              widget.prompt.displayTitle,
              style: const TextStyle(
                color: AppColors.textPrimary,
                fontSize: 22,
                fontWeight: FontWeight.bold,
                height: 1.3,
              ),
            ),

            const SizedBox(height: 20),

            // 3. Prompt Text Container (Free vs. Locked Blur Overlay)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: AppColors.surface,
                borderRadius: BorderRadius.circular(18),
                border: Border.all(
                  color: isUnlocked ? AppColors.primary.withOpacity(0.4) : AppColors.locked.withOpacity(0.4),
                  width: 1.5,
                ),
              ),
              child: isUnlocked
                  ? Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: const [
                            Text(
                              'AI PROMPT TEXT',
                              style: TextStyle(
                                color: AppColors.textMuted,
                                fontSize: 11,
                                fontWeight: FontWeight.bold,
                                letterSpacing: 1.2,
                              ),
                            ),
                            Icon(Icons.check_circle_rounded, color: AppColors.unlocked, size: 18),
                          ],
                        ),
                        const SizedBox(height: 12),
                        SelectableText(
                          widget.prompt.prompt,
                          style: const TextStyle(
                            color: AppColors.textPrimary,
                            fontSize: 15,
                            height: 1.6,
                            fontFamily: 'monospace',
                          ),
                        ),
                      ],
                    )
                  : Column(
                      children: [
                        const Icon(Icons.lock_rounded, color: AppColors.locked, size: 48),
                        const SizedBox(height: 12),
                        const Text(
                          '🔒 Locked Premium Prompt',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 6),
                        const Text(
                          'Watch a short video ad to unlock the full prompt text and copy button.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: AppColors.textSecondary, fontSize: 13),
                        ),
                        const SizedBox(height: 20),
                        SizedBox(
                          width: double.infinity,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: AppColors.locked,
                              foregroundColor: Colors.black,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                            ),
                            onPressed: _isAdLoading ? null : _watchAdToUnlock,
                            icon: _isAdLoading
                                ? const SizedBox(
                                    width: 20,
                                    height: 20,
                                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black),
                                  )
                                : const Icon(Icons.play_circle_fill_rounded, color: Colors.black),
                            label: Text(
                              _isAdLoading ? 'Loading Ad...' : 'Watch Ad to Unlock',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                            ),
                          ),
                        ),
                      ],
                    ),
            ),

            const SizedBox(height: 20),

            // 4. Tags Section
            if (widget.prompt.tagList.isNotEmpty) ...[
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: widget.prompt.tagList.map((tag) {
                  return Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    decoration: BoxDecoration(
                      color: AppColors.surfaceLight,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      '#$tag',
                      style: const TextStyle(color: AppColors.textSecondary, fontSize: 12),
                    ),
                  );
                }).toList(),
              ),
              const SizedBox(height: 20),
            ],

            // 5. Actions (Copy Prompt + Open in AI Tools)
            if (isUnlocked) ...[
              SizedBox(
                width: double.infinity,
                height: 54,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppColors.primary,
                    elevation: 4,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  ),
                  onPressed: () {
                    ClipboardUtil.copyToClipboard(context, widget.prompt.prompt);
                    context.read<PromptProvider>().incrementCopyCount(widget.prompt.id);
                  },
                  icon: const Icon(Icons.copy_rounded, color: Colors.white),
                  label: Text(
                    'Copy Prompt Text (${widget.prompt.copiesCount})',
                    style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                ),
              ),

              const SizedBox(height: 16),

              const Text(
                'OPEN DIRECTLY IN AI TOOLS',
                style: TextStyle(
                  color: AppColors.textMuted,
                  fontSize: 11,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 1.2,
                ),
              ),

              const SizedBox(height: 10),

              Row(
                children: [
                  // ChatGPT
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        side: const BorderSide(color: Color(0xFF10A37F), width: 1.5),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () {
                        HapticFeedback.lightImpact();
                        ClipboardUtil.copyToClipboard(context, widget.prompt.prompt);
                        final encoded = Uri.encodeComponent(widget.prompt.prompt);
                        _launchExternalUrl('https://chatgpt.com/?q=$encoded');
                      },
                      icon: const Icon(Icons.smart_toy_rounded, color: Color(0xFF10A37F), size: 18),
                      label: const Text(
                        'ChatGPT',
                        style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),

                  const SizedBox(width: 8),

                  // Bing AI Creator
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        side: const BorderSide(color: Color(0xFF0078D4), width: 1.5),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () {
                        HapticFeedback.lightImpact();
                        ClipboardUtil.copyToClipboard(context, widget.prompt.prompt);
                        final encoded = Uri.encodeComponent(widget.prompt.prompt);
                        _launchExternalUrl('https://www.bing.com/images/create?q=$encoded');
                      },
                      icon: const Icon(Icons.palette_rounded, color: Color(0xFF0078D4), size: 18),
                      label: const Text(
                        'Bing AI',
                        style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),

                  const SizedBox(width: 8),

                  // Claude AI
                  Expanded(
                    child: OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        side: const BorderSide(color: Color(0xFFD97706), width: 1.5),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () {
                        HapticFeedback.lightImpact();
                        ClipboardUtil.copyToClipboard(context, widget.prompt.prompt);
                        _launchExternalUrl('https://claude.ai/');
                      },
                      icon: const Icon(Icons.psychology_rounded, color: Color(0xFFD97706), size: 18),
                      label: const Text(
                        'Claude',
                        style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),
                ],
              ),
            ],

            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }
}
