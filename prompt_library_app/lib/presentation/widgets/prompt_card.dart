import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:provider/provider.dart';
import '../../core/constants/app_colors.dart';
import '../../core/utils/clipboard_util.dart';
import '../../data/models/prompt_model.dart';
import '../../logic/providers/favorites_provider.dart';
import '../../logic/providers/prompt_provider.dart';
import '../screens/details/prompt_details_screen.dart';

class PromptCard extends StatelessWidget {
  final PromptModel prompt;

  const PromptCard({super.key, required this.prompt});

  @override
  Widget build(BuildContext context) {
    final bool isThumbnail = prompt.category.toLowerCase().contains('thumb');

    return GestureDetector(
      onTap: () {
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => PromptDetailsScreen(prompt: prompt),
          ),
        );
      },
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.border, width: 1),
          boxShadow: const [
            BoxShadow(
              color: Colors.black26,
              blurRadius: 10,
              offset: Offset(0, 4),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Preview Image Container with Aspect Ratio (16:9 for Thumbnail category)
            Stack(
              children: [
                Hero(
                  tag: 'prompt_img_${prompt.id}',
                  child: AspectRatio(
                    aspectRatio: isThumbnail ? 16 / 9 : 1.0,
                    child: prompt.previewImageUrl.isNotEmpty
                        ? CachedNetworkImage(
                            imageUrl: prompt.previewImageUrl,
                            fit: BoxFit.cover,
                            placeholder: (context, url) => Container(
                              color: AppColors.surfaceLight,
                              child: const Center(
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: AppColors.primary,
                                ),
                              ),
                            ),
                            errorWidget: (context, url, error) => Container(
                              color: AppColors.surfaceLight,
                              child: const Icon(
                                Icons.auto_awesome_rounded,
                                color: AppColors.primaryAccent,
                                size: 40,
                              ),
                            ),
                          )
                        : Container(
                            color: AppColors.surfaceLight,
                            child: const Center(
                              child: Icon(
                                Icons.auto_awesome_rounded,
                                color: AppColors.primaryAccent,
                                size: 40,
                              ),
                            ),
                          ),
                  ),
                ),

                // Dark Bottom Gradient Vignette Scrim (Enhances contrast)
                Positioned.fill(
                  child: IgnorePointer(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [
                            Colors.transparent,
                            Colors.transparent,
                            Colors.black.withOpacity(0.65),
                          ],
                          stops: const [0.0, 0.55, 1.0],
                        ),
                      ),
                    ),
                  ),
                ),

                // Top Floating Badges (Trending & Locked Status)
                Positioned(
                  top: 8,
                  left: 8,
                  right: 8,
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      if (prompt.isChallenge || prompt.isPopular)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: prompt.isPopular ? Colors.orangeAccent : AppColors.trending,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                prompt.isPopular ? Icons.whatshot_rounded : Icons.local_fire_department_rounded,
                                color: Colors.white,
                                size: 12,
                              ),
                              const SizedBox(width: 3),
                              Text(
                                prompt.isPopular ? 'Popular' : 'Trending',
                                style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                              ),
                            ],
                          ),
                        )
                      else
                        const SizedBox.shrink(),

                      // Lock Badge & Favorite Button
                      Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Consumer<FavoritesProvider>(
                            builder: (context, favProvider, _) {
                              final isFav = favProvider.isFavorite(prompt.id.toString());
                              return GestureDetector(
                                onTap: () {
                                  HapticFeedback.lightImpact();
                                  favProvider.toggleFavorite(prompt);
                                  ScaffoldMessenger.of(context).hideCurrentSnackBar();
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      duration: const Duration(seconds: 1),
                                      content: Text(isFav ? 'Removed from Favorites' : 'Saved to Favorites ❤️'),
                                    ),
                                  );
                                },
                                child: Container(
                                  padding: const EdgeInsets.all(5),
                                  decoration: const BoxDecoration(
                                    color: Colors.black54,
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(
                                    isFav ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                                    color: isFav ? Colors.redAccent : Colors.white,
                                    size: 15,
                                  ),
                                ),
                              );
                            },
                          ),
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: prompt.isLocked ? AppColors.locked : AppColors.unlocked.withOpacity(0.2),
                              borderRadius: BorderRadius.circular(20),
                              border: Border.all(
                                color: prompt.isLocked ? AppColors.locked : AppColors.unlocked,
                                width: 1,
                              ),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  prompt.isLocked ? Icons.lock_rounded : Icons.lock_open_rounded,
                                  color: prompt.isLocked ? Colors.black : AppColors.unlocked,
                                  size: 11,
                                ),
                                const SizedBox(width: 3),
                                Text(
                                  prompt.isLocked ? 'Locked' : 'Free',
                                  style: TextStyle(
                                    color: prompt.isLocked ? Colors.black : AppColors.unlocked,
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),

                // 1-Tap Quick Copy Micro-Pill Button
                Positioned(
                  bottom: 7,
                  right: 7,
                  child: Consumer<PromptProvider>(
                    builder: (context, promptProvider, _) {
                      final isUnlocked = !prompt.isLocked || promptProvider.isPromptUnlocked(prompt.id);
                      return GestureDetector(
                        onTap: () {
                          HapticFeedback.lightImpact();
                          if (!isUnlocked) {
                            Navigator.push(
                              context,
                              MaterialPageRoute(
                                builder: (_) => PromptDetailsScreen(prompt: prompt),
                              ),
                            );
                          } else {
                            ClipboardUtil.copyToClipboard(context, prompt.prompt);
                            promptProvider.incrementCopyCount(prompt.id);
                          }
                        },
                        child: Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: Colors.black.withOpacity(0.65),
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(
                              color: isUnlocked ? AppColors.primaryAccent.withOpacity(0.6) : AppColors.locked.withOpacity(0.6),
                              width: 0.8,
                            ),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                isUnlocked ? Icons.copy_rounded : Icons.lock_outline_rounded,
                                color: isUnlocked ? Colors.white : AppColors.locked,
                                size: 11,
                              ),
                              const SizedBox(width: 4),
                              Text(
                                isUnlocked ? 'Copy' : 'Unlock',
                                style: TextStyle(
                                  color: isUnlocked ? Colors.white : AppColors.locked,
                                  fontSize: 10,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ],
            ),

            // Card Details Padding
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Category Pill & Like Count Row
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withOpacity(0.15),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          prompt.category,
                          style: const TextStyle(
                            color: AppColors.primaryAccent,
                            fontSize: 10,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),

                      // Interactive Highlighted Social Media Like Button with DB Sync
                      Consumer<PromptProvider>(
                        builder: (context, provider, _) {
                          final bool isLiked = provider.isLiked(prompt.id);
                          return GestureDetector(
                            onTap: () {
                              HapticFeedback.lightImpact();
                              provider.toggleLike(prompt.id);
                            },
                            child: AnimatedContainer(
                              duration: const Duration(milliseconds: 200),
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                              decoration: BoxDecoration(
                                color: isLiked ? Colors.pinkAccent.withOpacity(0.2) : AppColors.surfaceLight.withOpacity(0.3),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(
                                  color: isLiked ? Colors.pinkAccent : AppColors.border,
                                  width: 1,
                                ),
                              ),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(
                                    isLiked ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                                    color: isLiked ? Colors.pinkAccent : AppColors.textMuted,
                                    size: 13,
                                  ),
                                  const SizedBox(width: 4),
                                  Text(
                                    '${prompt.likesCount}',
                                    style: TextStyle(
                                      color: isLiked ? Colors.pinkAccent : AppColors.textSecondary,
                                      fontSize: 11,
                                      fontWeight: isLiked ? FontWeight.bold : FontWeight.normal,
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

                  const SizedBox(height: 8),

                  // Prompt Title
                  Text(
                    prompt.displayTitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: AppColors.textPrimary,
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                      height: 1.3,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
