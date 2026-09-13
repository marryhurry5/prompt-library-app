class PromptModel {
  final int id;
  final String category;
  final String outputType;
  final String tags;
  final String prompt;
  final String textOutput; // Title/Caption
  final bool isChallenge;
  final String createdAt;
  final String username;
  final List<String> imageUrls;
  final String? videoUrl;
  final bool isLocked;
  final int likesCount;
  final int copiesCount;

  PromptModel({
    required this.id,
    required this.category,
    required this.outputType,
    required this.tags,
    required this.prompt,
    required this.textOutput,
    required this.isChallenge,
    required this.createdAt,
    required this.username,
    required this.imageUrls,
    this.videoUrl,
    this.isLocked = false,
    this.likesCount = 0,
    this.copiesCount = 0,
  });

  factory PromptModel.fromJson(Map<String, dynamic> json) {
    final rawImages = json['image_urls'] as List<dynamic>? ?? [];
    final imageList = rawImages.map((e) => e.toString()).toList();
    final bool challenge = (json['is_challenge'] == 1 || json['is_challenge'] == true);

    // Locked status rule: Every 4th prompt or special trending/challenge prompts are locked
    final int promptId = json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0;
    final bool autoLock = (challenge || (promptId % 4 == 0));
    final int likes = json['likes'] is int ? json['likes'] : int.tryParse(json['likes']?.toString() ?? '0') ?? 0;
    final int copies = json['copies'] is int ? json['copies'] : int.tryParse(json['copies']?.toString() ?? '0') ?? 0;

    return PromptModel(
      id: promptId,
      category: json['category'] ?? 'General',
      outputType: json['output_type'] ?? 'Image',
      tags: json['tags'] ?? '',
      prompt: json['prompt'] ?? '',
      textOutput: json['text_output'] ?? 'AI Prompt',
      isChallenge: challenge,
      createdAt: json['created_at'] ?? '',
      username: json['username'] ?? '@anonymous',
      imageUrls: imageList,
      videoUrl: json['video_url'],
      isLocked: autoLock,
      likesCount: likes,
      copiesCount: copies,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'category': category,
      'output_type': outputType,
      'tags': tags,
      'prompt': prompt,
      'text_output': textOutput,
      'is_challenge': isChallenge ? 1 : 0,
      'created_at': createdAt,
      'username': username,
      'image_urls': imageUrls,
      'video_url': videoUrl,
      'likes': likesCount,
      'copies': copiesCount,
    };
  }

  PromptModel copyWith({bool? isLocked, int? likesCount, int? copiesCount}) {
    return PromptModel(
      id: id,
      category: category,
      outputType: outputType,
      tags: tags,
      prompt: prompt,
      textOutput: textOutput,
      isChallenge: isChallenge,
      createdAt: createdAt,
      username: username,
      imageUrls: imageUrls,
      videoUrl: videoUrl,
      isLocked: isLocked ?? this.isLocked,
      likesCount: likesCount ?? this.likesCount,
      copiesCount: copiesCount ?? this.copiesCount,
    );
  }

  String get displayTitle => textOutput.isNotEmpty ? textOutput : 'AI Prompt #$id';

  String get previewImageUrl => imageUrls.isNotEmpty ? imageUrls.first : '';

  bool get isPopular => copiesCount >= 50 || likesCount >= 50;

  List<String> get tagList {
    if (tags.isEmpty) return [];
    return tags.split(',').map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
  }
}
