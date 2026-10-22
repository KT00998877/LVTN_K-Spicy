import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'router.dart';
import 'theme.dart';
import '../features/auth/presentation/auth_controller.dart';

final _messengerKey = GlobalKey<ScaffoldMessengerState>();

class MicayApp extends ConsumerWidget {
  const MicayApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    ref.listen(authControllerProvider, (previous, next) {
      if (next.isAuthenticated &&
          next.error != null &&
          previous?.error != next.error) {
        _messengerKey.currentState?.showSnackBar(
          SnackBar(content: Text(next.error!.message)),
        );
      }
    });
    return MaterialApp.router(
      scaffoldMessengerKey: _messengerKey,
      title: 'Mì Cay',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      routerConfig: router,
    );
  }
}
