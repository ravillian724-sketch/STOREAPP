class MedicalInfoModel {
  int? medicalInfoId;
  int? medicalInfoUsersId;
  int? medicalInfoAge;
  int? medicalInfoHeight;
  int? medicalInfoWeight;
  String? medicalInfoGender;
  String? medicalInfoChronicDiseases;
  String? medicalInfoAllergies;
  String? medicalInfoCurrentMedications;
  String? medicalInfoBloodType;
  String? medicalInfoAdditionalNotes;
  String? medicalInfoCreatedate;

  MedicalInfoModel(
      {this.medicalInfoId,
      this.medicalInfoUsersId,
      this.medicalInfoAge,
      this.medicalInfoHeight,
      this.medicalInfoWeight,
      this.medicalInfoGender,
      this.medicalInfoChronicDiseases,
      this.medicalInfoAllergies,
      this.medicalInfoCurrentMedications,
      this.medicalInfoBloodType,
      this.medicalInfoAdditionalNotes,
      this.medicalInfoCreatedate});

  MedicalInfoModel.fromJson(Map<String, dynamic> json) {
    medicalInfoId = json['medical_info_id'];
    medicalInfoUsersId = json['medical_info_users_id'];
    medicalInfoAge = json['medical_info_age'];
    medicalInfoHeight = json['medical_info_height'];
    medicalInfoWeight = json['medical_info_weight'];
    medicalInfoGender = json['medical_info_gender'];
    medicalInfoChronicDiseases = json['medical_info_chronic_diseases'];
    medicalInfoAllergies = json['medical_info_allergies'];
    medicalInfoCurrentMedications = json['medical_info_current_medications'];
    medicalInfoBloodType = json['medical_info_blood_type'];
    medicalInfoAdditionalNotes = json['medical_info_additional_notes'];
    medicalInfoCreatedate = json['medical_info_createdate'];
  }

  Map<String, dynamic> toJson() {
    final Map<String, dynamic> data = <String, dynamic>{};
    data['medical_info_id'] = medicalInfoId;
    data['medical_info_users_id'] = medicalInfoUsersId;
    data['medical_info_age'] = medicalInfoAge;
    data['medical_info_height'] = medicalInfoHeight;
    data['medical_info_weight'] = medicalInfoWeight;
    data['medical_info_gender'] = medicalInfoGender;
    data['medical_info_chronic_diseases'] = medicalInfoChronicDiseases;
    data['medical_info_allergies'] = medicalInfoAllergies;
    data['medical_info_current_medications'] = medicalInfoCurrentMedications;
    data['medical_info_blood_type'] = medicalInfoBloodType;
    data['medical_info_additional_notes'] = medicalInfoAdditionalNotes;
    data['medical_info_createdate'] = medicalInfoCreatedate;
    return data;
  }
}
