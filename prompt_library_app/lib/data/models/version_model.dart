class VersionModel {
  final String latestVersion;
  final String minSupportedVersion;
  final bool forceUpdate;
  final String updateUrl;
  final String releaseNotes;

  VersionModel({
    required this.latestVersion,
    required this.minSupportedVersion,
    required this.forceUpdate,
    required this.updateUrl,
    required this.releaseNotes,
  });

  factory VersionModel.fromJson(Map<String, dynamic> json) {
    return VersionModel(
      latestVersion: json['latest_version'] ?? '1.0.0',
      minSupportedVersion: json['min_supported_version'] ?? '1.0.0',
      forceUpdate: json['force_update'] == true || json['force_update'] == 1,
      updateUrl: json['update_url'] ?? '',
      releaseNotes: json['release_notes'] ?? 'New version available.',
    );
  }
}
