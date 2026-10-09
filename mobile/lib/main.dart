import 'package:flutter/material.dart';
import 'screens/login_screen.dart';

void main() {
  runApp(const KojoliPro());
}

class KojoliPro extends StatelessWidget {
  const KojoliPro({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Kojoli Pro',
      theme: ThemeData(
        primarySwatch: Colors.green,
      ),
      home: const LoginScreen(),
    );
  }
}