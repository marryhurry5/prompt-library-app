import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import '../../../core/constants/app_colors.dart';
import '../../../logic/providers/search_provider.dart';
import '../../widgets/prompt_card.dart';
import '../../widgets/shimmer_skeleton_card.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final TextEditingController _searchController = TextEditingController();

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final searchProvider = context.watch<SearchProvider>();

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
          onPressed: () {
            searchProvider.clearSearch();
            Navigator.pop(context);
          },
        ),
        title: TextField(
          controller: _searchController,
          autofocus: true,
          style: const TextStyle(color: Colors.white, fontSize: 16),
          decoration: InputDecoration(
            hintText: 'Search title, prompt, tags...',
            hintStyle: const TextStyle(color: AppColors.textMuted, fontSize: 15),
            border: InputBorder.none,
            suffixIcon: _searchController.text.isNotEmpty
                ? IconButton(
                    icon: const Icon(Icons.clear_rounded, color: AppColors.textMuted),
                    onPressed: () {
                      _searchController.clear();
                      searchProvider.clearSearch();
                    },
                  )
                : null,
          ),
          onChanged: (query) {
            searchProvider.search(query);
          },
        ),
      ),
      body: Column(
        children: [
          const Divider(color: AppColors.border, height: 1),
          Expanded(
            child: searchProvider.isSearching
                ? GridView.builder(
                    padding: const EdgeInsets.all(16),
                    gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                      crossAxisCount: 2,
                      mainAxisSpacing: 14,
                      crossAxisSpacing: 14,
                      childAspectRatio: 0.65,
                    ),
                    itemCount: 4,
                    itemBuilder: (context, index) => const ShimmerSkeletonCard(),
                  )
                : searchProvider.searchError != null
                    ? Center(
                        child: Text(
                          searchProvider.searchError!,
                          style: const TextStyle(color: AppColors.textMuted),
                        ),
                      )
                    : searchProvider.searchResults.isEmpty
                        ? (_searchController.text.isEmpty
                            ? SingleChildScrollView(
                                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: const [
                                        Icon(Icons.local_fire_department_rounded, color: AppColors.trending, size: 20),
                                        SizedBox(width: 8),
                                        Text(
                                          'Popular Searches',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 16,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 14),
                                    Wrap(
                                      spacing: 10,
                                      runSpacing: 10,
                                      children: [
                                        'Midjourney v6',
                                        'YouTube Thumbnail',
                                        'Cyberpunk 3D',
                                        'Cinematic Portrait',
                                        'Anime Style',
                                        'Logo Design',
                                        'Photorealistic 8K',
                                        'Vector Illustration',
                                        'Dark Fantasy',
                                      ].map((tag) {
                                        return GestureDetector(
                                          onTap: () {
                                            HapticFeedback.lightImpact();
                                            _searchController.text = tag;
                                            searchProvider.search(tag);
                                          },
                                          child: Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                                            decoration: BoxDecoration(
                                              color: AppColors.surface,
                                              borderRadius: BorderRadius.circular(20),
                                              border: Border.all(color: AppColors.border, width: 1),
                                            ),
                                            child: Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                const Icon(Icons.search_rounded, color: AppColors.primaryAccent, size: 14),
                                                const SizedBox(width: 6),
                                                Text(
                                                  tag,
                                                  style: const TextStyle(
                                                    color: Colors.white,
                                                    fontSize: 13,
                                                    fontWeight: FontWeight.w500,
                                                  ),
                                                ),
                                              ],
                                            ),
                                          ),
                                        );
                                      }).toList(),
                                    ),
                                  ],
                                ),
                              )
                            : Center(
                                child: Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    const Icon(
                                      Icons.search_off_rounded,
                                      color: AppColors.textMuted,
                                      size: 60,
                                    ),
                                    const SizedBox(height: 12),
                                    const Text(
                                      'No matching prompts found',
                                      style: TextStyle(
                                        color: AppColors.textSecondary,
                                        fontSize: 15,
                                      ),
                                    ),
                                    const SizedBox(height: 16),
                                    ElevatedButton(
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: AppColors.primary,
                                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                      ),
                                      onPressed: () {
                                        _searchController.clear();
                                        searchProvider.clearSearch();
                                      },
                                      child: const Text('Clear Search', style: TextStyle(color: Colors.white)),
                                    ),
                                  ],
                                ),
                              ))
                        : GridView.builder(
                            padding: const EdgeInsets.all(16),
                            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 2,
                              mainAxisSpacing: 14,
                              crossAxisSpacing: 14,
                              childAspectRatio: 0.65,
                            ),
                            itemCount: searchProvider.searchResults.length,
                            itemBuilder: (context, index) {
                              return PromptCard(prompt: searchProvider.searchResults[index]);
                            },
                          ),
          ),
        ],
      ),
    );
  }
}
