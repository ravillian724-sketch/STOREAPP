class FeatureFlags {
  final Map<String, bool> _flags;

  const FeatureFlags({
    Map<String, bool> flags = const {},
  }) : _flags = flags;

  bool enabled(String feature) => _flags[feature] ?? false;

  Map<String, bool> get all => Map.unmodifiable(_flags);

  bool get aiCustomerAssistant => enabled('ai_customer_assistant');

  bool get aiMerchantCopilot => enabled('ai_merchant_copilot');

  bool get loyalty => enabled('loyalty');

  bool get tabby => enabled('tabby');

  bool get tamara => enabled('tamara');

  bool get multiBranch => enabled('multi_branch');

  bool get prescription => enabled('prescription');
}
