import axios from 'axios';

const servicesApi = axios.create({
  baseURL: import.meta.env.VITE_SERVICES_URL || '/services/api/v1',
});

servicesApi.interceptors.response.use(
  (response) => response,
  (error) => {
    error.message = error.response?.data?.message || error.message || 'Layanan pendukung gagal';
    return Promise.reject(error);
  }
);

export default servicesApi;
