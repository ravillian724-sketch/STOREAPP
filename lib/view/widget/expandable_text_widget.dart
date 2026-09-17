import 'package:ecommerce_app/core/constant/color.dart';
import 'package:flutter/material.dart';

class ExpandableTextWidget extends StatefulWidget {
  final String text;
  const ExpandableTextWidget({super.key, required this.text});

  @override
  State<ExpandableTextWidget> createState() => _ExpandableTextWidgetState();
}

class _ExpandableTextWidgetState extends State<ExpandableTextWidget> {
  late String firstHalf;
  late String secondHalf;
  bool hiddenText = true;
  static const int textLengthLimit = 250;
  @override
  void initState() {
    super.initState();
    if (widget.text.length > textLengthLimit) {
      firstHalf = widget.text.substring(0, textLengthLimit);
      secondHalf = widget.text.substring(textLengthLimit);
    } else {
      firstHalf = widget.text;
      secondHalf = "";
    }
  }

  @override
  Widget build(BuildContext context) {
    return secondHalf.isEmpty
        ? Text(
            firstHalf,
            style: const TextStyle(fontSize: 16),
          )
        : Column(
            //  child: secondHalf.isEmpty?SmallText(color: AppColors.paraColor,size:Dimensions.font16,text: firstHalf):Column(
            children: [
              Text(
                hiddenText ? ("$firstHalf...") : (firstHalf + secondHalf),
                style: const TextStyle(fontSize: 16),
              ),
              // SmallText(height: 1.8,color: AppColors.paraColor,size: Dimensions.font16,text:hiddenText?(firstHalf+"..."):(firstHalf+secondHalf)),
              InkWell(
                onTap: () {
                  setState(() {
                    hiddenText = !hiddenText;
                  });
                },
                child: Row(
                  children: [
                    const Text(
                      "Show more",
                      style: TextStyle(color: AppColor.primaryColor),
                    ),
                    Icon(
                      hiddenText ? Icons.arrow_drop_down : Icons.arrow_drop_up,
                      color: AppColor.primaryColor,
                    ),
                  ],
                ),
              )
            ],
          );
  }
}
