import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:provider/provider.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/services/notification_service.dart';
import '../../../core/services/update_service.dart';
import '../../../core/services/url_service.dart';
import '../../../data/models/category_model.dart';
import '../../../logic/providers/prompt_provider.dart';
import '../../widgets/prompt_card.dart';
import '../../widgets/shimmer_skeleton_card.dart';
import '../categories/categories_screen.dart';
import '../details/prompt_details_screen.dart';
import '../favorites/favorites_screen.dart';
import '../search/search_screen.dart';
import '../shop/shop_screen.dart';
import '../trending/trending_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final ScrollController _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<PromptProvider>().fetchPrompts(refresh: true);
      UpdateService.checkForUpdate(context);
      NotificationService.scheduleDailyEveningDigest();
    });

    _scrollController.addListener(() {
      if (_scrollController.position.pixels >=
          _scrollController.position.maxScrollExtent - 200) {
        context.read<PromptProvider>().loadMore();
      }
    });
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final promptProvider = context.watch<PromptProvider>();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        titleSpacing: 16,
        centerTitle: false,
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(7),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.primary, AppColors.primaryAccent],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(10),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.primary.withOpacity(0.35),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: const Icon(
                Icons.auto_awesome_rounded,
                color: Colors.white,
                size: 18,
              ),
            ),
            const SizedBox(width: 10),
            const Flexible(
              child: Text(
                'AI Prompt Hub',
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: AppColors.textPrimary,
                  fontSize: 19,
                  fontWeight: FontWeight.w700,
                  letterSpacing: -0.3,
                ),
              ),
            ),
          ],
        ),
        actions: [
          // Sleek Trending Action
          IconButton(
            icon: const Icon(Icons.whatshot_rounded, color: AppColors.trending, size: 22),
            tooltip: 'Trending Challenges & Prompts',
            visualDensity: VisualDensity.compact,
            onPressed: () {
              Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const TrendingScreen()),
              );
            },
          ),
          // Clean, Dedicated Shop Pill Button with Generous Margin (No Bleeding)
          Padding(
            padding: const EdgeInsets.only(left: 4, right: 14, top: 10, bottom: 10),
            child: InkWell(
              borderRadius: BorderRadius.circular(20),
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (_) => const ShopScreen()),
                );
              },
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      AppColors.primaryAccent.withOpacity(0.18),
                      AppColors.primary.withOpacity(0.10),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: AppColors.primaryAccent.withOpacity(0.4),
                    width: 1,
                  ),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: const [
                    Icon(
                      Icons.shopping_bag_rounded,
                      color: AppColors.primaryAccent,
                      size: 15,
                    ),
                    SizedBox(width: 5),
                    Text(
                      'Shop',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),

      // Main Scrollable Feed
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: () => promptProvider.fetchPrompts(refresh: true),
        child: CustomScrollView(
          controller: _scrollController,
          slivers: [
            // 1. Search Entry Banner
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                child: GestureDetector(
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const SearchScreen()),
                    );
                  },
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                    decoration: BoxDecoration(
                      color: AppColors.surface,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: AppColors.border, width: 1),
                    ),
                    child: Row(
                      children: const [
                        Icon(Icons.search_rounded, color: AppColors.textMuted),
                        SizedBox(width: 12),
                        Text(
                          'Search AI prompts, styles, tags...',
                          style: TextStyle(color: AppColors.textMuted, fontSize: 14),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),

            // Hero Trending Carousel
            if (promptProvider.prompts.isNotEmpty) ...[
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: const [
                          Icon(Icons.local_fire_department_rounded, color: AppColors.trending, size: 20),
                          SizedBox(width: 6),
                          Text(
                            'Trending Challenges',
                            style: TextStyle(
                              color: AppColors.textPrimary,
                              fontSize: 16,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ],
                      ),
                      GestureDetector(
                        onTap: () {
                          Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const TrendingScreen()),
                          );
                        },
                        child: const Text(
                          'See All >',
                          style: TextStyle(
                            color: AppColors.primaryAccent,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 165,
                  child: ListView.separated(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    scrollDirection: Axis.horizontal,
                    itemCount: (promptProvider.trendingPrompts.isNotEmpty
                            ? promptProvider.trendingPrompts
                            : promptProvider.prompts)
                        .take(6)
                        .length,
                    separatorBuilder: (_, __) => const SizedBox(width: 12),
                    itemBuilder: (context, index) {
                      final prompt = (promptProvider.trendingPrompts.isNotEmpty
                              ? promptProvider.trendingPrompts
                              : promptProvider.prompts)[index];
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
                          width: 270,
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(20),
                            border: Border.all(color: AppColors.border, width: 1),
                            boxShadow: const [
                              BoxShadow(color: Colors.black38, blurRadius: 8, offset: Offset(0, 4)),
                            ],
                          ),
                          clipBehavior: Clip.antiAlias,
                          child: Stack(
                            children: [
                              // Background Image
                              Positioned.fill(
                                child: prompt.previewImageUrl.isNotEmpty
                                    ? CachedNetworkImage(
                                        imageUrl: prompt.previewImageUrl,
                                        fit: BoxFit.cover,
                                        placeholder: (_, __) => Container(color: AppColors.surfaceLight),
                                        errorWidget: (_, __, ___) => Container(color: AppColors.surfaceLight),
                                      )
                                    : Container(color: AppColors.surfaceLight),
                              ),
                              // Gradient Overlay
                              Positioned.fill(
                                child: DecoratedBox(
                                  decoration: BoxDecoration(
                                    gradient: LinearGradient(
                                      begin: Alignment.topCenter,
                                      end: Alignment.bottomCenter,
                                      colors: [
                                        Colors.black.withOpacity(0.2),
                                        Colors.black.withOpacity(0.85),
                                      ],
                                      stops: const [0.3, 1.0],
                                    ),
                                  ),
                                ),
                              ),
                              // Top Badges
                              Positioned(
                                top: 10,
                                left: 10,
                                right: 10,
                                child: Row(
                                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color: AppColors.trending,
                                        borderRadius: BorderRadius.circular(14),
                                      ),
                                      child: Row(
                                        children: const [
                                          Icon(Icons.whatshot_rounded, color: Colors.white, size: 12),
                                          SizedBox(width: 3),
                                          Text(
                                            'HOT',
                                            style: TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                                          ),
                                        ],
                                      ),
                                    ),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                      decoration: BoxDecoration(
                                        color: Colors.black54,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text(
                                        prompt.category,
                                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w600),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              // Bottom Content
                              Positioned(
                                bottom: 12,
                                left: 12,
                                right: 12,
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      prompt.displayTitle,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 14,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    Row(
                                      children: [
                                        Text(
                                          prompt.username,
                                          style: const TextStyle(color: AppColors.primaryAccent, fontSize: 11, fontWeight: FontWeight.w600),
                                        ),
                                        const Spacer(),
                                        const Icon(Icons.favorite_rounded, color: Colors.pinkAccent, size: 12),
                                        const SizedBox(width: 3),
                                        Text(
                                          '${prompt.likesCount}',
                                          style: const TextStyle(color: Colors.white70, fontSize: 11),
                                        ),
                                      ],
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: 18)),
            ],

            // 2. Horizontal Categories Pill Bar
            SliverToBoxAdapter(
              child: SizedBox(
                height: 44,
                child: ListView.separated(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  scrollDirection: Axis.horizontal,
                  itemCount: promptProvider.categories.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 8),
                  itemBuilder: (context, index) {
                    final cat = promptProvider.categories[index];
                    final isSelected = promptProvider.selectedCategory == cat.name;

                    return GestureDetector(
                      onTap: () {
                        promptProvider.fetchPrompts(category: cat.name);
                      },
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                        decoration: BoxDecoration(
                          color: isSelected ? AppColors.primary : AppColors.surface,
                          borderRadius: BorderRadius.circular(24),
                          border: Border.all(
                            color: isSelected ? AppColors.primary : AppColors.border,
                            width: 1,
                          ),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              cat.icon,
                              size: 16,
                              color: isSelected ? Colors.white : AppColors.textSecondary,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              cat.name,
                              style: TextStyle(
                                color: isSelected ? Colors.white : AppColors.textSecondary,
                                fontSize: 13,
                                fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
            ),

            const SliverToBoxAdapter(child: SizedBox(height: 16)),

            // 3. Section Title
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      promptProvider.selectedCategory == 'All'
                          ? 'Explore Prompts'
                          : promptProvider.selectedCategory,
                      style: const TextStyle(
                        color: AppColors.textPrimary,
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    GestureDetector(
                      onTap: () {
                        Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => const CategoriesScreen()),
                        );
                      },
                      child: const Text(
                        'See Categories',
                        style: TextStyle(
                          color: AppColors.primaryAccent,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // 4. Content State (Loading, Error, or Prompt Grid)
            if (promptProvider.isLoading && promptProvider.prompts.isEmpty)
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 14,
                    crossAxisSpacing: 14,
                    childAspectRatio: 0.65,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (context, index) => const ShimmerSkeletonCard(),
                    childCount: 6,
                  ),
                ),
              )
            else if (promptProvider.errorMessage != null && promptProvider.prompts.isEmpty)
              SliverFillRemaining(
                child: Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.wifi_off_rounded, color: AppColors.textMuted, size: 50),
                      const SizedBox(height: 12),
                      Text(
                        promptProvider.errorMessage!,
                        style: const TextStyle(color: AppColors.textSecondary, fontSize: 14),
                        textAlign: TextAlign.center,
                      ),
                      const SizedBox(height: 16),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primary,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () => promptProvider.fetchPrompts(refresh: true),
                        child: const Text('Retry', style: TextStyle(color: Colors.white)),
                      ),
                    ],
                  ),
                ),
              )
            else if (promptProvider.prompts.isEmpty)
              const SliverFillRemaining(
                child: Center(
                  child: Text(
                    'No published prompts found in this category.',
                    style: TextStyle(color: AppColors.textMuted, fontSize: 14),
                  ),
                ),
              )
            else
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 14,
                    crossAxisSpacing: 14,
                    childAspectRatio: 0.65,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (context, index) {
                      return PromptCard(prompt: promptProvider.prompts[index]);
                    },
                    childCount: promptProvider.prompts.length,
                  ),
                ),
              ),

            // 5. Pagination Loading Indicator
            if (promptProvider.isMoreLoading)
              const SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.all(20),
                  child: Center(
                    child: SpinKitThreeBounce(
                      color: AppColors.primary,
                      size: 24,
                    ),
                  ),
                ),
              ),

            const SliverToBoxAdapter(child: SizedBox(height: 140)),
          ],
        ),
      ),

      // 7. Floating Action Button: Redirect to Telegram Bot for Prompt Submission
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.primary,
        elevation: 6,
        onPressed: () async {
          try {
            await UrlService.openTelegramBot();
          } catch (e) {
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Could not open Telegram Bot: $e')),
              );
            }
          }
        },
        icon: const Icon(Icons.send_rounded, color: Colors.white, size: 20),
        label: const Text(
          'Submit Prompt & Earn Money 💰',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
        ),
      ),
    );
  }
}
