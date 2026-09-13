import 'package:flutter/material.dart';
import 'package:flutter_spinkit/flutter_spinkit.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/services/api_service.dart';
import '../../../data/models/prompt_model.dart';
import '../../widgets/prompt_card.dart';

class CreatorProfileScreen extends StatefulWidget {
  final String username;

  const CreatorProfileScreen({super.key, required this.username});

  @override
  State<CreatorProfileScreen> createState() => _CreatorProfileScreenState();
}

class _CreatorProfileScreenState extends State<CreatorProfileScreen> {
  final ApiService _apiService = ApiService();
  bool _isLoading = true;
  Map<String, dynamic>? _creatorInfo;
  List<PromptModel> _prompts = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadCreatorProfile();
  }

  Future<void> _loadCreatorProfile() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final info = await _apiService.getCreatorInfo(widget.username);
      final response = await _apiService.getPrompts(creator: widget.username, page: 1);

      if (mounted) {
        setState(() {
          _creatorInfo = info;
          _prompts = response.prompts;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = 'Failed to load creator profile';
          _isLoading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final cleanUsername = widget.username.startsWith('@') ? widget.username : '@${widget.username}';
    final badge = _creatorInfo?['badge'] ?? '🚀 Creator';
    final totalPrompts = _creatorInfo?['total_prompts'] ?? _prompts.length;
    final totalLikes = _creatorInfo?['total_likes'] ?? 0;
    final totalCopies = _creatorInfo?['total_copies'] ?? 0;

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
          cleanUsername,
          style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
        ),
      ),
      body: _isLoading
          ? const Center(
              child: SpinKitFadingCube(color: AppColors.primary, size: 40),
            )
          : RefreshIndicator(
              color: AppColors.primary,
              onRefresh: _loadCreatorProfile,
              child: CustomScrollView(
                slivers: [
                  // 1. Creator Profile Header Card
                  SliverToBoxAdapter(
                    child: Container(
                      margin: const EdgeInsets.all(16),
                      padding: const EdgeInsets.all(20),
                      decoration: BoxDecoration(
                        color: AppColors.surface,
                        borderRadius: BorderRadius.circular(24),
                        border: Border.all(color: AppColors.border, width: 1),
                        boxShadow: const [
                          BoxShadow(color: Colors.black26, blurRadius: 10, offset: Offset(0, 4)),
                        ],
                      ),
                      child: Column(
                        children: [
                          // Avatar & Username
                          Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: AppColors.primary.withOpacity(0.15),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(
                              Icons.account_circle_rounded,
                              color: AppColors.primaryAccent,
                              size: 50,
                            ),
                          ),
                          const SizedBox(height: 12),
                          Text(
                            cleanUsername,
                            style: const TextStyle(
                              color: AppColors.textPrimary,
                              fontSize: 20,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 6),

                          // Rank Badge Chip
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                            decoration: BoxDecoration(
                              color: AppColors.trending.withOpacity(0.2),
                              borderRadius: BorderRadius.circular(20),
                              border: Border.all(color: AppColors.trending, width: 1),
                            ),
                            child: Text(
                              badge,
                              style: const TextStyle(
                                color: Colors.amberAccent,
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),

                          const SizedBox(height: 20),
                          const Divider(color: AppColors.border, height: 1),
                          const SizedBox(height: 16),

                          // Creator Statistics Row
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [
                              _buildStatItem('Prompts', '$totalPrompts', Icons.auto_awesome_rounded, AppColors.primaryAccent),
                              _buildStatItem('Total Likes', '$totalLikes', Icons.favorite_rounded, Colors.redAccent),
                              _buildStatItem('Copies', '$totalCopies', Icons.content_copy_rounded, AppColors.unlocked),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),

                  // 2. Section Header
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
                      child: Text(
                        'Published Prompts ($totalPrompts)',
                        style: const TextStyle(
                          color: AppColors.textPrimary,
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ),

                  // 3. Creator Prompts Grid
                  if (_prompts.isEmpty)
                    const SliverFillRemaining(
                      child: Center(
                        child: Text(
                          'No published prompts found for this creator.',
                          style: TextStyle(color: AppColors.textMuted, fontSize: 14),
                        ),
                      ),
                    )
                  else
                    SliverPadding(
                      padding: const EdgeInsets.all(16),
                      sliver: SliverGrid(
                        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 2,
                          mainAxisSpacing: 14,
                          crossAxisSpacing: 14,
                          childAspectRatio: 0.72,
                        ),
                        delegate: SliverChildBuilderDelegate(
                          (context, index) {
                            return PromptCard(prompt: _prompts[index]);
                          },
                          childCount: _prompts.length,
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  Widget _buildStatItem(String label, String value, IconData icon, Color color) {
    return Column(
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: color, size: 16),
            const SizedBox(width: 4),
            Text(
              value,
              style: const TextStyle(
                color: AppColors.textPrimary,
                fontSize: 16,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: const TextStyle(
            color: AppColors.textSecondary,
            fontSize: 12,
          ),
        ),
      ],
    );
  }
}
