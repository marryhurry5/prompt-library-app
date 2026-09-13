import 'dart:io';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:image_picker/image_picker.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import '../../../core/constants/api_endpoints.dart';
import '../../../core/constants/app_colors.dart';
import '../../../data/models/shop_item_model.dart';
import '../../../logic/providers/shop_provider.dart';

class AdminShopScreen extends StatefulWidget {
  const AdminShopScreen({super.key});

  @override
  State<AdminShopScreen> createState() => _AdminShopScreenState();
}

class _AdminShopScreenState extends State<AdminShopScreen> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _titleController = TextEditingController();
  final TextEditingController _descController = TextEditingController();
  final TextEditingController _imageUrlController = TextEditingController();
  final TextEditingController _accessLinkController = TextEditingController();

  String _selectedType = 'Reel Bundle';
  bool _isUploadingCover = false;
  bool _isPublishing = false;
  File? _selectedCoverFile;

  final List<String> _productTypes = [
    'Reel Bundle',
    'PDF E-Book',
    'Prompt Pack',
    'Drive Link',
  ];

  @override
  void dispose() {
    _titleController.dispose();
    _descController.dispose();
    _imageUrlController.dispose();
    _accessLinkController.dispose();
    super.dispose();
  }

  /// Picks Cover Image from phone gallery & uploads to Hostinger server
  Future<void> _pickAndUploadCover() async {
    final ImagePicker picker = ImagePicker();
    final XFile? image = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
    );

    if (image == null) return;

    setState(() {
      _selectedCoverFile = File(image.path);
      _isUploadingCover = true;
    });

    try {
      final request = http.MultipartRequest(
        'POST',
        Uri.parse('${ApiEndpoints.baseUrl}?action=upload_shop_media'),
      );
      request.files.add(await http.MultipartFile.fromPath('file', image.path));

      final streamedResponse = await request.send();
      final response = await http.Response.fromStream(streamedResponse);

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true && data['url'] != null) {
          setState(() {
            _imageUrlController.text = data['url'];
          });
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Cover image uploaded successfully! 📸')),
            );
          }
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to upload image: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isUploadingCover = false);
    }
  }

  /// Submits new product to database
  Future<void> _submitProduct() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isPublishing = true);

    final success = await context.read<ShopProvider>().addShopItem(
          title: _titleController.text.trim(),
          description: _descController.text.trim(),
          imageUrl: _imageUrlController.text.trim(),
          accessLink: _accessLinkController.text.trim(),
          itemType: _selectedType,
        );

    setState(() => _isPublishing = false);

    if (mounted) {
      if (success) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('🎉 Product Published to Shop Successfully!'),
            backgroundColor: AppColors.unlocked,
          ),
        );
        _titleController.clear();
        _descController.clear();
        _imageUrlController.clear();
        _accessLinkController.clear();
        setState(() {
          _selectedCoverFile = null;
        });
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Failed to publish product. Please check fields.'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    }
  }

  /// Displays pre-filled Edit Product Dialog modal
  void _showEditProductDialog(ShopItemModel item) {
    final titleEdit = TextEditingController(text: item.title);
    final descEdit = TextEditingController(text: item.description);
    final imageEdit = TextEditingController(text: item.imageUrl);
    final linkEdit = TextEditingController(text: item.accessLink);
    String typeEdit = item.itemType;
    bool isSaving = false;

    showDialog(
      context: context,
      builder: (editCtx) {
        return StatefulBuilder(
          builder: (context, setEditState) {
            return AlertDialog(
              backgroundColor: AppColors.surface,
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
              title: Row(
                children: const [
                  Icon(Icons.edit_rounded, color: AppColors.primary, size: 22),
                  SizedBox(width: 8),
                  Text('Edit Product', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
                ],
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    DropdownButtonFormField<String>(
                      value: typeEdit,
                      dropdownColor: AppColors.surface,
                      decoration: InputDecoration(
                        labelText: 'Product Type',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surfaceLight,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      items: _productTypes.map((t) => DropdownMenuItem(value: t, child: Text(t, style: const TextStyle(color: Colors.white)))).toList(),
                      onChanged: (v) => setEditState(() => typeEdit = v!),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: titleEdit,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'Title',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surfaceLight,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: imageEdit,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'Cover Image URL',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surfaceLight,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: linkEdit,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'PDF / Drive Access Link',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surfaceLight,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: descEdit,
                      maxLines: 2,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'Description',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surfaceLight,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(editCtx),
                  child: const Text('Cancel', style: TextStyle(color: AppColors.textMuted)),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: AppColors.primary),
                  onPressed: isSaving
                      ? null
                      : () async {
                          setEditState(() => isSaving = true);
                          final ok = await context.read<ShopProvider>().editShopItem(
                                id: item.id,
                                title: titleEdit.text.trim(),
                                description: descEdit.text.trim(),
                                imageUrl: imageEdit.text.trim(),
                                accessLink: linkEdit.text.trim(),
                                itemType: typeEdit,
                              );
                          if (mounted) {
                            Navigator.pop(editCtx);
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(ok ? 'Product updated successfully! 🎉' : 'Failed to update product.'),
                                backgroundColor: ok ? AppColors.unlocked : Colors.redAccent,
                              ),
                            );
                          }
                        },
                  child: Text(isSaving ? 'Saving...' : 'Update Product', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                ),
              ],
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final shopProvider = context.watch<ShopProvider>();

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        backgroundColor: AppColors.background,
        appBar: AppBar(
          backgroundColor: AppColors.background,
          elevation: 0,
          leading: IconButton(
            icon: const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
            onPressed: () => Navigator.pop(context),
          ),
          title: Row(
            children: const [
              Icon(Icons.admin_panel_settings_rounded, color: AppColors.primary, size: 24),
              SizedBox(width: 8),
              Text(
                'Shop Admin Panel',
                style: TextStyle(color: AppColors.textPrimary, fontSize: 18, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          actions: [
            IconButton(
              icon: const Icon(Icons.logout_rounded, color: Colors.redAccent),
              tooltip: 'Logout Admin',
              onPressed: () async {
                await shopProvider.adminLogout();
                if (mounted) Navigator.pop(context);
              },
            ),
          ],
          bottom: const TabBar(
            indicatorColor: AppColors.primary,
            labelColor: AppColors.primary,
            unselectedLabelColor: AppColors.textMuted,
            tabs: [
              Tab(icon: Icon(Icons.add_box_rounded), text: 'Add Product'),
              Tab(icon: Icon(Icons.list_alt_rounded), text: 'Manage Items'),
            ],
          ),
        ),
        body: TabBarView(
          children: [
            // TAB 1: Add Product Form
            SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'CREATE NEW DIGITAL PRODUCT',
                      style: TextStyle(
                        color: AppColors.textMuted,
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 1.2,
                      ),
                    ),

                    const SizedBox(height: 16),

                    // Product Type Dropdown
                    DropdownButtonFormField<String>(
                      value: _selectedType,
                      dropdownColor: AppColors.surface,
                      decoration: InputDecoration(
                        labelText: 'Product Type',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surface,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                      items: _productTypes.map((type) {
                        return DropdownMenuItem(
                          value: type,
                          child: Text(type, style: const TextStyle(color: Colors.white)),
                        );
                      }).toList(),
                      onChanged: (val) {
                        if (val != null) setState(() => _selectedType = val);
                      },
                    ),

                    const SizedBox(height: 16),

                    // Title Input
                    TextFormField(
                      controller: _titleController,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'Product Title',
                        hintText: 'e.g. 1000+ Viral Reels Bundle 🚀',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surface,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                      validator: (val) => val == null || val.isEmpty ? 'Title is required' : null,
                    ),

                    const SizedBox(height: 16),

                    // Cover Image Picker / Upload
                    Row(
                      children: [
                        Expanded(
                          child: TextFormField(
                            controller: _imageUrlController,
                            style: const TextStyle(color: Colors.white),
                            decoration: InputDecoration(
                              labelText: 'Cover Image URL',
                              hintText: 'https://...',
                              labelStyle: const TextStyle(color: AppColors.textSecondary),
                              filled: true,
                              fillColor: AppColors.surface,
                              border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                            ),
                            validator: (val) => val == null || val.isEmpty ? 'Image URL is required' : null,
                          ),
                        ),
                        const SizedBox(width: 10),
                        ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.surfaceLight,
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                          ),
                          onPressed: _isUploadingCover ? null : _pickAndUploadCover,
                          icon: _isUploadingCover
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                )
                              : const Icon(Icons.upload_file_rounded, color: AppColors.primaryAccent),
                          label: const Text('Upload', style: TextStyle(color: Colors.white, fontSize: 13)),
                        ),
                      ],
                    ),

                    const SizedBox(height: 16),

                    // Access / PDF Link Input
                    TextFormField(
                      controller: _accessLinkController,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'PDF / Drive Access Link',
                        hintText: 'https://drive.google.com/file/d/... or PDF URL',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surface,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                      validator: (val) => val == null || val.isEmpty ? 'Access link is required' : null,
                    ),

                    const SizedBox(height: 16),

                    // Description Input
                    TextFormField(
                      controller: _descController,
                      maxLines: 3,
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        labelText: 'Description / Details',
                        hintText: 'Explain what users get in this bundle...',
                        labelStyle: const TextStyle(color: AppColors.textSecondary),
                        filled: true,
                        fillColor: AppColors.surface,
                        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
                      ),
                    ),

                    const SizedBox(height: 24),

                    // Submit Button
                    SizedBox(
                      width: double.infinity,
                      height: 52,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primary,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: _isPublishing ? null : _submitProduct,
                        icon: _isPublishing
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                              )
                            : const Icon(Icons.publish_rounded, color: Colors.white),
                        label: Text(
                          _isPublishing ? 'Publishing...' : 'Publish Product to Shop',
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // TAB 2: Manage Items Table
            shopProvider.items.isEmpty
                ? const Center(
                    child: Text('No products published yet.', style: TextStyle(color: AppColors.textMuted)),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: shopProvider.items.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (context, index) {
                      final item = shopProvider.items[index];
                      return Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: AppColors.border),
                        ),
                        child: Row(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Image.network(
                                item.imageUrl,
                                width: 50,
                                height: 50,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => Container(
                                  width: 50,
                                  height: 50,
                                  color: AppColors.surfaceLight,
                                  child: const Icon(Icons.broken_image_rounded, color: AppColors.textMuted),
                                ),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    item.title,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14,
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    item.itemType,
                                    style: const TextStyle(color: AppColors.primaryAccent, fontSize: 11),
                                  ),
                                ],
                              ),
                            ),

                            // Edit Button
                            IconButton(
                              icon: const Icon(Icons.edit_rounded, color: AppColors.primaryAccent),
                              onPressed: () => _showEditProductDialog(item),
                            ),

                            // Delete Button
                            IconButton(
                              icon: const Icon(Icons.delete_forever_rounded, color: Colors.redAccent),
                              onPressed: () async {
                                final confirm = await showDialog<bool>(
                                  context: context,
                                  builder: (ctx) => AlertDialog(
                                    backgroundColor: AppColors.surface,
                                    title: const Text('Delete Product?'),
                                    content: Text('Remove "${item.title}" from shop?'),
                                    actions: [
                                      TextButton(
                                        onPressed: () => Navigator.pop(ctx, false),
                                        child: const Text('Cancel'),
                                      ),
                                      ElevatedButton(
                                        style: ElevatedButton.styleFrom(backgroundColor: Colors.redAccent),
                                        onPressed: () => Navigator.pop(ctx, true),
                                        child: const Text('Delete'),
                                      ),
                                    ],
                                  ),
                                );

                                if (confirm == true) {
                                  await context.read<ShopProvider>().deleteShopItem(item.id);
                                }
                              },
                            ),
                          ],
                        ),
                      );
                    },
                  ),
          ],
        ),
      ),
    );
  }
}
