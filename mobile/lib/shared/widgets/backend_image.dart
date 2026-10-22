import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../core/utils/backend_image_url.dart';

/// Public backend images use a native HTML image on web so static storage
/// files do not need CORS headers. Android/iOS retain disk caching.
class BackendImage extends StatelessWidget {
  const BackendImage({
    super.key,
    required this.imageUrl,
    this.fit,
    this.width,
    this.height,
    this.placeholder,
    this.errorWidget,
  });

  final String imageUrl;
  final BoxFit? fit;
  final double? width;
  final double? height;
  final Widget Function(BuildContext, String)? placeholder;
  final Widget Function(BuildContext, String, Object)? errorWidget;

  @override
  Widget build(BuildContext context) {
    final url = backendImageUrl(imageUrl);
    Widget failure(Object error) => errorWidget?.call(context, url, error) ??
        const Center(child: Icon(Icons.broken_image_outlined));

    if (url.isEmpty) return failure(const FormatException('Missing image URL'));
    if (kIsWeb) {
      return Image.network(
        url,
        fit: fit,
        width: width,
        height: height,
        webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
        errorBuilder: (_, error, ___) => failure(error),
        frameBuilder: (context, child, frame, synchronous) =>
            synchronous || frame != null
                ? child
                : placeholder?.call(context, url) ?? child,
      );
    }
    return CachedNetworkImage(
      imageUrl: url,
      fit: fit,
      width: width,
      height: height,
      placeholder: placeholder,
      errorWidget: errorWidget,
    );
  }
}
