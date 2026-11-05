import 'package:flutter/material.dart';

import '../../network/api_exception.dart';

/// Plain-language text for a failed server call. Offline is explained, not shown as an error code.
String explain(Object e) {
  if (e is ApiException) return e.isNetwork ? 'Offline — this screen needs a connection.' : e.message;
  return 'Something went wrong.';
}

/// Loads something from the server and shows progress / a retryable error / the content. [reload] re-runs the loader.
class OnlineData<T> extends StatefulWidget {
  const OnlineData({super.key, required this.load, required this.builder});
  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;
  @override
  State<OnlineData<T>> createState() => _OnlineDataState<T>();
}

class _OnlineDataState<T> extends State<OnlineData<T>> {
  T? _data;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    if (mounted) setState(() => _loading = true);
    try {
      final d = await widget.load();
      _data = d;
      _error = null;
    } catch (e) {
      _error = explain(e);
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) return const Center(child: CircularProgressIndicator());
    if (_error != null && _data == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [Text(_error!, key: const Key('online-error'), textAlign: TextAlign.center), TextButton(onPressed: reload, child: const Text('Retry'))]),
        ),
      );
    }
    return widget.builder(context, _data as T, reload);
  }
}

void snack(BuildContext context, String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message), duration: const Duration(seconds: 6)));
