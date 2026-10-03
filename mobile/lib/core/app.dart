import 'package:flutter/material.dart';

class KojoliProApp extends StatelessWidget {
  const KojoliProApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: Scaffold(
        appBar: AppBar(
          title: const Text('Kojoli Pro'),
        ),
        body: const Center(
          child: Text('Welcome to Kojoli Pro'),
        ),
      ),
    );
  }
}