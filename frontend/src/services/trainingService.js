import api from './api'
export const getMyTraining = () => api.get('/me/training')
export const getTrainingCourse = (courseId) => api.get(`/me/training/${courseId}`)
export const completeTrainingLesson = (courseId, lessonId) => api.post(`/me/training/${courseId}/lessons/${lessonId}/complete`)
export const getTrainingQuiz = (courseId) => api.get(`/me/training/${courseId}/quiz`)
export const submitTrainingQuiz = (courseId, answers) => api.post(`/me/training/${courseId}/quiz`, { answers })
